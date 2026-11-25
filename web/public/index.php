<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']); // blocks cross-site form posts
session_start();
require_once('configuration/configuration.php');

const CLASSES = [1 => 'Feca', 'Osamodas', 'Anutrof', 'Sram', 'Xelor', 'Zurcarák', 'Aniripsa', 'Yopuka', 'Ocra', 'Sadida', 'Sacrógrito', 'Pandawa'];

// Same rules as GameClient.addCharacter in the game server
function invalidCharacterName($name) {
	$n = strtolower($name);
	if (strlen($n) < 3 || strlen($n) > 20) return 'El nombre debe tener entre 3 y 20 caracteres.';
	if (!preg_match('/^[a-z-]+$/', $n)) return 'Solo letras sin tildes y guiones (-).';
	if (preg_match('/([a-z])\1\1/', $n)) return 'No repitas la misma letra tres veces seguidas.';
	if (substr_count($n, '-') > 6) return 'Máximo 6 guiones.';
	foreach (['modo', 'admin', 'puta'] as $bad) if (strpos($n, $bad) !== false) return 'Ese nombre no está permitido.';
	return null;
}

$msg = null; $ok = false;
$action = $_POST['action'] ?? '';

if ($action === 'register') {
	$user = trim($_POST['username'] ?? '');
	$pass = $_POST['password'] ?? '';
	$nick = trim($_POST['nickname'] ?? '');
	if ($user === '' || $pass === '' || $nick === '') $msg = 'Completa todos los campos.';
	elseif (strlen($user) > 30 || strlen($nick) > 30) $msg = 'El nombre de cuenta y el apodo no pueden tener más de 30 caracteres.';
	// The login server crashes on accounts without a nickname, so ask for it here (same rules as its ChooseNickName)
	elseif (!preg_match('/^[A-Za-z0-9.@-]+$/', $nick)) $msg = 'El apodo solo puede tener letras, números y . @ -';
	elseif (strcasecmp($nick, $user) === 0) $msg = 'El apodo no puede ser igual al nombre de cuenta.';
	elseif (checkString($user) || checkString($pass)) $msg = 'Usa solo letras, números y los símbolos _ - . ! ?';
	elseif ($pass !== ($_POST['repeat'] ?? '')) $msg = 'Las contraseñas no coinciden.';
	else {
		$q = $login->prepare('SELECT COUNT(*) FROM world_accounts WHERE account = ?');
		$q->execute([$user]);
		$p = $login->prepare('SELECT COUNT(*) FROM world_accounts WHERE pseudo = ?');
		$p->execute([$nick]);
		if ($q->fetchColumn() > 0) $msg = 'Ese nombre de cuenta ya existe.';
		elseif ($p->fetchColumn() > 0) $msg = 'Ese apodo ya lo usa otra cuenta.';
		else {
			// Same hash the login server checks: SHA512(md5(pass))
			$login->prepare('INSERT INTO world_accounts(account, pass, pseudo, dateRegister) VALUES (?, ?, ?, ?)')
				->execute([$user, hash('sha512', md5($pass)), $nick, date('d/m/Y')]);
			$ok = true; $msg = 'Cuenta creada. Ya puedes entrar aquí o en el juego.';
		}
	}
} elseif ($action === 'login') {
	$q = $login->prepare('SELECT guid FROM world_accounts WHERE account = ? AND pass = ?');
	$q->execute([$_POST['username'] ?? '', hash('sha512', md5($_POST['password'] ?? ''))]);
	$id = $q->fetchColumn();
	if ($id) { session_regenerate_id(true); $_SESSION['id'] = (int)$id; $_SESSION['user'] = $_POST['username']; }
	else $msg = 'Cuenta o contraseña incorrecta.';
} elseif ($action === 'logout') {
	session_destroy(); $_SESSION = [];
} elseif ($action === 'rename' && isset($_SESSION['id'])) {
	$name = trim($_POST['name'] ?? '');
	$name = ucfirst(strtolower($name));
	$q = $login->prepare('SELECT logged FROM world_accounts WHERE guid = ?');
	$q->execute([$_SESSION['id']]);
	if ($q->fetchColumn() > 0) $msg = 'Sal del juego antes de cambiar el nombre.';
	elseif ($err = invalidCharacterName($name)) $msg = $err;
	else {
		$q = $login->prepare('SELECT COUNT(*) FROM world_players WHERE name = ?');
		$q->execute([$name]);
		if ($q->fetchColumn() > 0) $msg = 'Ese nombre ya lo usa otro personaje.';
		else {
			// account check: a player can only rename their own characters
			$q = $login->prepare('UPDATE world_players SET name = ? WHERE id = ? AND account = ?');
			$q->execute([$name, (int)($_POST['id'] ?? 0), $_SESSION['id']]);
			// shortcut: the game server caches loaded characters, so the new name shows in game after its next restart
			if ($q->rowCount()) { $ok = true; $msg = "Nombre cambiado a $name. Se verá en el juego después del próximo reinicio del servidor."; }
			else $msg = 'Personaje no encontrado.';
		}
	}
}

// --- Item stat editor: edits are queued in web_item_edits and applied live by the game server (ObjectData.applyWebEdits) ---
$STATS = json_decode(file_get_contents(__DIR__ . '/data/stats.json'), true);
$ITEMS = null;
$EQUIP = json_decode(file_get_contents(__DIR__ . '/data/equip_types.json'), true); // item type => name, equipment only
function itemInfo($template) { global $ITEMS; $ITEMS ??= json_decode(file_get_contents(__DIR__ . '/data/items.json'), true); return $ITEMS[$template] ?? ["Objeto #$template", 0, 0]; }
// object id => character name, for the inventories of this account
function ownedItems($login, $accountId) {
	$q = $login->prepare('SELECT name, objets FROM world_players WHERE account = ?');
	$q->execute([$accountId]);
	$owned = [];
	foreach ($q as $p) foreach (explode('|', $p['objets']) as $id) if (preg_match('/^\d+$/', $id)) $owned[(int)$id] = $p['name'];
	return $owned;
}
// "7d#32#0#0#0d0+50,..." => [statId => value], only the editable stats
function parseStats($str) {
	global $STATS; $out = [];
	foreach (explode(',', (string)$str) as $line) {
		$p = explode('#', $line);
		if (count($p) >= 2 && preg_match('/^[0-9a-f]+$/i', $p[0]) && isset($STATS[hexdec($p[0])])) $out[hexdec($p[0])] = hexdec($p[1]);
	}
	return $out;
}

if ($action === 'stats' && isset($_SESSION['id'])) {
	$objectId = (int)($_POST['object'] ?? 0);
	$q = $login->prepare('SELECT template, stats FROM world_objects WHERE id = ?');
	$q->execute([$objectId]);
	$row = $q->fetch(PDO::FETCH_ASSOC);
	$current = $row ? $row['stats'] : false;
	if ($current === false || !isset(ownedItems($login, $_SESSION['id'])[$objectId])) $msg = 'Ese objeto no está en tus personajes.';
	elseif (!isset($EQUIP[itemInfo($row['template'])[1]])) $msg = 'Solo se pueden editar objetos de equipo.';
	else {
		$current = parseStats($current);
		$wanted = [];
		foreach ((array)($_POST['value'] ?? []) as $id => $v) if (isset($current[(int)$id]) && empty($_POST['remove'][$id])) $wanted[(int)$id] = (int)$v;
		$newId = (int)($_POST['new_stat'] ?? 0);
		if ($newId && isset($STATS[$newId])) $wanted[$newId] = (int)($_POST['new_value'] ?? 0);
		$diff = [];
		foreach ($wanted as $id => $v) {
			if ($v < 1 || $v > 9999) { $msg = 'Los valores deben estar entre 1 y 9999.'; break; }
			if (($current[$id] ?? null) !== $v) $diff[] = "$id:$v";
		}
		foreach ($current as $id => $v) if (!isset($wanted[$id])) $diff[] = "$id:0"; // 0 = remove
		if (!$msg && !$diff) $msg = 'No cambiaste nada.';
		elseif (!$msg) {
			try {
				$login->prepare('INSERT INTO web_item_edits(object_id, stats) VALUES (?, ?)')->execute([$objectId, implode(';', $diff)]);
				$ok = true; $msg = 'Cambio enviado. El juego lo aplica en unos segundos (si estás en combate, al terminarlo).';
			} catch (PDOException $ex) { $msg = 'El servidor del juego todavía no está listo para recibir cambios. Inténtalo en un minuto.'; }
		}
	}
}

$players = [];
if (isset($_SESSION['id'])) {
	$q = $login->prepare('SELECT id, name, class, level FROM world_players WHERE account = ? ORDER BY level DESC');
	$q->execute([$_SESSION['id']]);
	$players = $q->fetchAll(PDO::FETCH_ASSOC);

	$owned = ownedItems($login, $_SESSION['id']);
	$objects = [];
	if ($owned) {
		$ids = implode(',', array_keys($owned)); // ints only, from ownedItems
		foreach ($login->query("SELECT id, template, position, stats FROM world_objects WHERE id IN ($ids)") as $o) {
			[$o['name'], $o['type']] = itemInfo($o['template']);
			if (isset($EQUIP[$o['type']])) $objects[(int)$o['id']] = $o;
		}
		// equipped first, then by type and name
		uasort($objects, fn($a, $b) => [$a['position'] == -1, $EQUIP[$a['type']], $a['name']] <=> [$b['position'] == -1, $EQUIP[$b['type']], $b['name']]);
	}
	$editing = $objects[(int)($_GET['item'] ?? 0)] ?? null;
	$pending = false;
	if ($editing) {
		try { $q = $login->prepare('SELECT COUNT(*) FROM web_item_edits WHERE object_id = ?'); $q->execute([$editing['id']]); $pending = $q->fetchColumn() > 0; }
		catch (PDOException $ex) {}
	}
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(TITLE) ?></title>
<style>
	body { font-family: system-ui, sans-serif; background: #1e1b16; color: #eee; margin: 0; padding: 24px 16px; }
	main { max-width: 640px; margin: auto; }
	h1 { text-align: center; color: #e8b64c; }
	section { background: #2b2720; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
	label { display: block; margin-top: 8px; }
	input { width: 100%; box-sizing: border-box; padding: 8px; margin-top: 4px; border-radius: 4px; border: 1px solid #555; background: #15130f; color: #eee; }
	button { margin-top: 12px; padding: 8px 16px; border: 0; border-radius: 4px; background: #e8b64c; color: #1e1b16; font-weight: bold; cursor: pointer; }
	.row { display: flex; gap: 16px; flex-wrap: wrap; } .row > section { flex: 1 1 260px; }
	.msg { padding: 12px; border-radius: 6px; margin-bottom: 16px; background: #6b2a2a; } .msg.ok { background: #2a5b33; }
	table { width: 100%; border-collapse: collapse; } td, th { padding: 6px; text-align: left; border-bottom: 1px solid #444; }
	td form { display: flex; gap: 6px; } td input, td button { margin: 0; }
	a { color: #e8b64c; } h3 { margin: 18px 0 4px; color: #e8b64c; } small { color: #aaa; }
	.badge { color: #7fd18b; font-weight: normal; } .preview { font-size: 13px; color: #ccc; } .preview span { white-space: nowrap; } [hidden] { display: none !important; }
	.ico { font-size: 20px; width: 28px; } .item-img { width: 120px; height: 120px; margin: 0 auto 12px; }
	.add { display: flex; gap: 8px; margin-top: 12px; } .add input { margin: 0; }
	select { flex: 1; padding: 8px; border-radius: 4px; border: 1px solid #555; background: #15130f; color: #eee; }
</style>
</head>
<body><main>
<h1><?= e(TITLE) ?></h1>
<?php if ($msg): ?><div class="msg<?= $ok ? ' ok' : '' ?>"><?= e($msg) ?></div><?php endif; ?>

<?php if (!isset($_SESSION['id'])): ?>
<div class="row">
	<section>
		<h2>Crear cuenta</h2>
		<form method="post"><input type="hidden" name="action" value="register">
			<label>Nombre de cuenta (para entrar) <input name="username" maxlength="30" required></label>
			<label>Apodo (lo ven los demás) <input name="nickname" maxlength="30" pattern="[A-Za-z0-9.@-]+" required></label>
			<label>Contraseña <input type="password" name="password" required></label>
			<label>Repetir contraseña <input type="password" name="repeat" required></label>
			<button>Crear cuenta</button>
		</form>
	</section>
	<section>
		<h2>Entrar</h2>
		<form method="post"><input type="hidden" name="action" value="login">
			<label>Nombre de cuenta <input name="username" required></label>
			<label>Contraseña <input type="password" name="password" required></label>
			<button>Entrar</button>
		</form>
	</section>
</div>
<?php else: ?>
<section>
	<form method="post" style="float:right"><input type="hidden" name="action" value="logout"><button style="margin:0">Salir</button></form>
	<h2>Cuenta: <?= e($_SESSION['user']) ?></h2>
	<?php if (!$players): ?>
		<p>Todavía no tienes personajes. Créalos desde el juego.</p>
	<?php else: ?>
	<table>
		<tr><th>Personaje</th><th>Clase</th><th>Nivel</th><th>Cambiar nombre</th></tr>
		<?php foreach ($players as $p): ?>
		<tr>
			<td><?= e($p['name']) ?></td>
			<td><?= e(CLASSES[$p['class']] ?? '?') ?></td>
			<td><?= (int)$p['level'] ?></td>
			<td><form method="post"><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
				<input name="name" maxlength="20" placeholder="Nuevo nombre" required aria-label="Nuevo nombre para <?= e($p['name']) ?>"><button>OK</button></form></td>
		</tr>
		<?php endforeach; ?>
	</table>
	<?php endif; ?>
</section>

<?php if ($editing): [$iname, $itype, $igfx] = itemInfo($editing['template']); $cur = parseStats($editing['stats']); ?>
<section>
	<a href="./" style="float:right">← Volver</a>
	<h2><?= e($iname) ?></h2>
	<p style="color:#aaa">Lo tiene: <?= e($owned[$editing['id']]) ?></p>
	<div class="item-img"><embed src="/dofus/items/<?= (int)$itype ?>/<?= (int)$igfx ?>.swf" width="120" height="120"></div>
	<?php if ($pending): ?><p class="msg">Hay un cambio pendiente. Recarga la página en unos segundos para ver las stats actualizadas.</p><?php endif; ?>
	<form method="post">
		<input type="hidden" name="action" value="stats"><input type="hidden" name="object" value="<?= (int)$editing['id'] ?>">
		<table>
			<tr><th></th><th>Stat</th><th>Valor</th><th>Quitar</th></tr>
			<?php foreach ($cur as $id => $v): ?>
			<tr>
				<td class="ico"><?= $STATS[$id][0] ?></td>
				<td><?= e($STATS[$id][1]) ?></td>
				<td><input type="number" name="value[<?= $id ?>]" value="<?= $v ?>" min="1" max="9999" style="width:90px" aria-label="Valor"></td>
				<td><input type="checkbox" name="remove[<?= $id ?>]" value="1" style="width:auto" aria-label="Quitar"></td>
			</tr>
			<?php endforeach; ?>
			<?php if (!$cur): ?><tr><td colspan="4">Este objeto no tiene stats.</td></tr><?php endif; ?>
		</table>
		<div class="add">
			<select name="new_stat" aria-label="Añadir stat"><option value="">+ Añadir stat…</option>
				<?php foreach ($STATS as $id => [$icon, $text]): if (isset($cur[$id])) continue; ?>
				<option value="<?= $id ?>"><?= $icon ?> <?= e(str_replace('#', 'X', $text)) ?></option>
				<?php endforeach; ?>
			</select>
			<input type="number" name="new_value" min="1" max="9999" placeholder="X" style="width:90px" aria-label="Valor de la stat nueva">
		</div>
		<button>Guardar cambios</button>
	</form>
</section>
<script>window.RufflePlayer = window.RufflePlayer || {}; window.RufflePlayer.config = { splashScreen: false, unmuteOverlay: 'hidden', contextMenu: 'off', backgroundColor: '#2b2720' };</script>
<script src="https://cdn.jsdelivr.net/npm/@ruffle-rs/ruffle@0.8.0-nightly.2026.11.8/ruffle.js"></script>
<?php elseif ($objects): ?>
<section>
	<h2>Equipo</h2>
	<input id="search" type="search" placeholder="Buscar por nombre o tipo (anillo, capa…)" aria-label="Buscar objeto">
	<?php foreach ($players as $p): $mine = array_filter($objects, fn($o) => $owned[$o['id']] === $p['name']); if (!$mine) continue; ?>
	<h3><?= e($p['name']) ?></h3>
	<table class="items">
		<?php foreach ($mine as $o): $st = parseStats($o['stats']); ?>
		<tr data-name="<?= e(mb_strtolower($o['name'] . ' ' . $EQUIP[$o['type']])) ?>">
			<td><a href="?item=<?= (int)$o['id'] ?>"><?= e($o['name']) ?></a><br><small><?= e($EQUIP[$o['type']]) ?><?= $o['position'] != -1 ? ' · <b class="badge">Equipado</b>' : '' ?></small></td>
			<td class="preview"><?php foreach ($st as $id => $v): ?><span title="<?= e(str_replace('#', $v, $STATS[$id][1])) ?>"><?= $STATS[$id][0] ?><?= $v ?></span> <?php endforeach; ?></td>
		</tr>
		<?php endforeach; ?>
	</table>
	<?php endforeach; ?>
</section>
<script>
document.getElementById('search').addEventListener('input', e => {
	const q = e.target.value.toLowerCase();
	for (const tr of document.querySelectorAll('.items tr')) tr.hidden = !tr.dataset.name.includes(q);
});
</script>
<?php endif; ?>
<?php endif; ?>
</main></body>
</html>

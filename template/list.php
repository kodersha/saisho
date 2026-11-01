<?php
include 'head.php';
?>
<strong><span role="heading"><a href="/"><?= saisho_escape(META_TITLE) ?></a></span></strong>
<div><p>Welcome to <a href="https://0xff.nu/saisho">Saisho Mk.2</a> version <?= saisho_escape(SAISHO_VERSION) ?>!</p>
<p>You can change this text in <code>template/list.php</code>.</p></div>
<div><?= $page->content ?></div>
<?php include 'footer.php'; ?>

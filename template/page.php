<?php
include 'head.php';
?>
<a href="/"><?= saisho_escape(META_TITLE) ?></a> / <strong><span role="heading" aria-level="1"><?= saisho_escape($page->content->title ?? 'untitled') ?></span></strong>
<div class="content"><?= $page->content->body ?? '' ?></div>
<div class="meta g">Last Modified: <?= saisho_escape($page->content->modified ?? '') ?></div>
<?php include 'footer.php'; ?>

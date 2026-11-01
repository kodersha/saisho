<?php

include 'head.php';

echo '<a href="' . saisho_escape(HOST) . '">' . saisho_escape(META_TITLE) . '</a> / ';
echo '<strong><span role="heading" aria-level="1">' . saisho_escape($page->content->title ?? 'untitled') . '</span></strong>';

$modified = (int)($page->content->modified ?? time());
?>
<div class="content"><?= $page->content->body ?? '' ?></div>
<div class="meta g">Last Modified: <?= saisho_escape(date('Y-m-d H:i:s', $modified)) ?></div>

<?php include 'footer.php'; ?>

<?php
/** @var array $_ */
$enabled = !empty($_['enabled']);
?>
<div id="recognaize-people-settings" class="section">
	<h2><?php p($l->t('recognAIze')); ?></h2>
	<p><?php p($l->t('Controls whether Memories\' People page is shown. This only flips the visibility flag Memories checks - it does not enable Recognize\'s own face classifier, which recognAIze replaces.')); ?></p>
	<p>
		<input type="checkbox" id="recognaize-people-enabled" class="checkbox" <?php p($enabled ? 'checked' : ''); ?>>
		<label for="recognaize-people-enabled"><?php p($l->t('Show the People page')); ?></label>
	</p>
	<p id="recognaize-people-status"></p>
</div>

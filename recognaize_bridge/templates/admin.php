<?php
/** @var array $_ */
$enabled = !empty($_['enabled']);
$paths = $_['paths'] ?? '';
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
<div id="recognaize-included-paths-settings" class="section">
	<h2><?php p($l->t('Included paths')); ?></h2>
	<p><?php p($l->t('Only files under these paths (relative to each user\'s files root, one per line) are considered for face recognition. Everything else is ignored. Leave empty to allow every path.')); ?></p>
	<p>
		<textarea id="recognaize-included-paths" rows="6" cols="50" placeholder="Photos&#10;Camera/2024"><?php p($paths); ?></textarea>
	</p>
	<p>
		<button id="recognaize-included-paths-save"><?php p($l->t('Save')); ?></button>
	</p>
	<p id="recognaize-included-paths-status"></p>
</div>

<?php
/** @var array $_ */
$enabled = !empty($_['enabled']);
$paths = $_['paths'] ?? '';
$automaticDetectionEnabled = !empty($_['automaticDetectionEnabled']);
$batchSize = $_['batchSize'] ?? 50;
$processedLastHour = $_['processedLastHour'] ?? 0;
$processedCount = $_['processedCount'] ?? 0;
$unprocessedCount = $_['unprocessedCount'] ?? 0;
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
		<button type="button" id="recognaize-included-paths-save"><?php p($l->t('Save')); ?></button>
	</p>
	<p id="recognaize-included-paths-status"></p>
</div>
<div id="recognaize-trigger-settings" class="section">
	<h2><?php p($l->t('Detection triggers')); ?></h2>
	<p>
		<input type="checkbox" id="recognaize-automatic-detection-enabled" class="checkbox" <?php p($automaticDetectionEnabled ? 'checked' : ''); ?>>
		<label for="recognaize-automatic-detection-enabled"><?php p($l->t('Automatically detect faces in new uploads')); ?></label>
	</p>
	<p id="recognaize-automatic-detection-status"></p>
	<p><?php p($l->t('Process existing files that haven\'t been checked yet (respects the included paths above). Runs in the background over the next few cron cycles, not immediately.')); ?></p>
	<p>
		<label for="recognaize-batch-size"><?php p($l->t('Batch size')); ?></label>
		<input type="number" id="recognaize-batch-size" min="1" value="<?php p((string)$batchSize); ?>">
		<button type="button" id="recognaize-run-batch"><?php p($l->t('Run now')); ?></button>
	</p>
	<p id="recognaize-run-batch-status"></p>
</div>
<div id="recognaize-stats-settings" class="section">
	<h2><?php p($l->t('Statistics')); ?></h2>
	<p><?php p($l->n('%n picture processed in the last hour', '%n pictures processed in the last hour', $processedLastHour, [$processedLastHour])); ?></p>
	<p><?php p($l->t('%s processed, %s not yet processed', [(string)$processedCount, (string)$unprocessedCount])); ?></p>
</div>

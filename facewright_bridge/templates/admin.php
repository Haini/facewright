<?php
/** @var array $_ */
$enabled = !empty($_['enabled']);
$paths = $_['paths'] ?? '';
$automaticDetectionEnabled = !empty($_['automaticDetectionEnabled']);
$automaticClusteringEnabled = !empty($_['automaticClusteringEnabled']);
$processingPaused = !empty($_['processingPaused']);
$batchSize = $_['batchSize'] ?? 50;
$clusterMaxDistance = $_['clusterMaxDistance'] ?? 0.5;
$clusterMinFaces = $_['clusterMinFaces'] ?? 3;
$clusterChunkSize = $_['clusterChunkSize'] ?? 300;
$processedLastHour = $_['processedLastHour'] ?? 0;
$processedCount = $_['processedCount'] ?? 0;
$unprocessedCount = $_['unprocessedCount'] ?? 0;
$unclusteredCount = $_['unclusteredCount'] ?? 0;
$queuedDetections = $_['queuedDetections'] ?? 0;
$scanActive = !empty($_['scanActive']);
$clusteringActive = !empty($_['clusteringActive']);
?>
<div id="facewright-people-settings" class="section">
	<h2><?php p($l->t('Facewright')); ?></h2>
	<p><?php p($l->t('Controls whether Memories\' People page is shown. This only flips the visibility flag Memories checks - it does not enable Recognize\'s own face classifier, which Facewright replaces.')); ?></p>
	<p>
		<input type="checkbox" id="facewright-people-enabled" class="checkbox" <?php p($enabled ? 'checked' : ''); ?>>
		<label for="facewright-people-enabled"><?php p($l->t('Show the People page')); ?></label>
	</p>
	<p id="facewright-people-status"></p>
</div>
<div id="facewright-included-paths-settings" class="section">
	<h2><?php p($l->t('Included paths')); ?></h2>
	<p><?php p($l->t('Only files under these paths (relative to each user\'s files root, one per line) are considered for face recognition. Everything else is ignored. Leave empty to allow every path.')); ?></p>
	<p>
		<textarea id="facewright-included-paths" rows="6" cols="50" placeholder="Photos&#10;Camera/2024"><?php p($paths); ?></textarea>
	</p>
	<p>
		<button type="button" id="facewright-included-paths-save"><?php p($l->t('Save')); ?></button>
	</p>
	<p id="facewright-included-paths-status"></p>
</div>
<div id="facewright-trigger-settings" class="section">
	<h2><?php p($l->t('Detection triggers')); ?></h2>
	<p>
		<input type="checkbox" id="facewright-automatic-detection-enabled" class="checkbox" <?php p($automaticDetectionEnabled ? 'checked' : ''); ?>>
		<label for="facewright-automatic-detection-enabled"><?php p($l->t('Automatically detect faces in new uploads')); ?></label>
	</p>
	<p id="facewright-automatic-detection-status"></p>
	<p>
		<input type="checkbox" id="facewright-processing-paused" class="checkbox" <?php p($processingPaused ? 'checked' : ''); ?>>
		<label for="facewright-processing-paused"><?php p($l->t('Pause processing')); ?></label>
	</p>
	<p><?php p($l->t('Stops Facewright from running new detections (from automatic uploads, "Run now", or "Scan entire library" alike). Already-queued files stay queued and pick back up automatically once unpaused - nothing is lost or needs restarting. Only affects Facewright\'s own background jobs, not Nextcloud\'s cron or any other app.')); ?></p>
	<p id="facewright-processing-paused-status"></p>
	<p><?php p($l->t('Process existing files that haven\'t been checked yet (respects the included paths above). Runs in the background over the next few cron cycles, not immediately.')); ?></p>
	<p>
		<label for="facewright-batch-size"><?php p($l->t('Batch size')); ?></label>
		<input type="number" id="facewright-batch-size" min="1" value="<?php p((string)$batchSize); ?>">
		<button type="button" id="facewright-run-batch"><?php p($l->t('Run now')); ?></button>
	</p>
	<p id="facewright-run-batch-status"></p>
	<p><?php p($l->t('For a large backlog, prefer this over a huge batch size above: it enqueues one batch-sized chunk at a time in the background, repeating automatically every cron cycle until the whole library is covered, so it never tries to queue everything at once.')); ?></p>
	<p>
		<button type="button" id="facewright-run-scan"><?php p($l->t('Scan entire library')); ?></button>
	</p>
	<p id="facewright-run-scan-status"></p>
</div>
<div id="facewright-clustering-settings" class="section">
	<h2><?php p($l->t('Clustering')); ?></h2>
	<p><?php p($l->t('Groups detected faces into people Memories can show and let you name. Prototype thresholds - see CLAUDE.md before trusting them at scale. Only newly-detected faces are considered on each run; already-clustered ones are left alone.')); ?></p>
	<p>
		<input type="checkbox" id="facewright-automatic-clustering-enabled" class="checkbox" <?php p($automaticClusteringEnabled ? 'checked' : ''); ?>>
		<label for="facewright-automatic-clustering-enabled"><?php p($l->t('Automatically re-cluster new faces')); ?></label>
	</p>
	<p><?php p($l->t('Detection runs continuously, but a clustering run stops itself once it runs out of progress to make - nothing restarts it as new faces get detected afterward. This periodically starts a new run (using the settings below) for any user with unclustered faces, so newly-detected people don\'t silently sit off Memories\' People page until someone clicks "Run now" again.')); ?></p>
	<p id="facewright-automatic-clustering-status"></p>
	<p>
		<label for="facewright-cluster-max-distance"><?php p($l->t('Max cosine distance')); ?></label>
		<input type="number" id="facewright-cluster-max-distance" min="0" max="2" step="0.01" value="<?php p((string)$clusterMaxDistance); ?>">
	</p>
	<p>
		<label for="facewright-cluster-min-faces"><?php p($l->t('Min faces to form a cluster')); ?></label>
		<input type="number" id="facewright-cluster-min-faces" min="1" value="<?php p((string)$clusterMinFaces); ?>">
	</p>
	<p>
		<label for="facewright-cluster-chunk-size"><?php p($l->t('Chunk size')); ?></label>
		<input type="number" id="facewright-cluster-chunk-size" min="1" value="<?php p((string)$clusterChunkSize); ?>">
		<button type="button" id="facewright-run-clustering"><?php p($l->t('Run now')); ?></button>
	</p>
	<p><?php p($l->t('Runs in the background, in chunks, across upcoming cron cycles - large accounts can have tens of thousands of faces to compare, which is too much work for one request. Started chains resume automatically after a pause and keep going until nothing changes.')); ?></p>
	<p id="facewright-run-clustering-status"></p>
</div>
<div id="facewright-merge-settings" class="section">
	<h2><?php p($l->t('Merge clusters')); ?></h2>
	<p><?php p($l->t('Combines two clusters that turned out to be the same person into one. Recognize\'s own rename UI refuses this - renaming a person to an existing name is treated as an error there ("Not allowed to create duplicate names"), not a merge request. This is exactly what you hit if you try to rename a newly-detected face to an existing person\'s name in Memories. This moves every face from "merge in" onto "keep", then deletes the now-empty cluster.')); ?></p>
	<p>
		<label for="facewright-merge-user"><?php p($l->t('User')); ?></label>
		<input type="text" id="facewright-merge-user" placeholder="e.g. alice">
	</p>
	<p>
		<label for="facewright-merge-keep"><?php p($l->t('Person to keep')); ?></label>
		<input type="text" id="facewright-merge-keep" placeholder="e.g. Jane Doe, or a numeric cluster ID">
	</p>
	<p>
		<label for="facewright-merge-id"><?php p($l->t('Person to merge in (deleted afterward)')); ?></label>
		<input type="text" id="facewright-merge-id" placeholder="e.g. 757, or a numeric cluster ID">
		<button type="button" id="facewright-merge-clusters"><?php p($l->t('Merge')); ?></button>
	</p>
	<p><?php p($l->t('Either field accepts a person\'s existing name, or a numeric cluster ID for an unnamed one - Memories shows an unnamed cluster\'s tile labeled with its numeric ID.')); ?></p>
	<p id="facewright-merge-clusters-status"></p>
</div>
<div id="facewright-stats-settings" class="section">
	<h2><?php p($l->t('Statistics')); ?></h2>
	<p><?php p($l->t('Snapshot as of this page load - reload to refresh.')); ?></p>
	<p>
		<strong>
		<?php if ($processingPaused): ?>
			<?php p($l->t('Processing is PAUSED.')); ?>
		<?php elseif ($scanActive && $clusteringActive): ?>
			<?php p($l->t('Library scan and clustering are both active.')); ?>
		<?php elseif ($scanActive): ?>
			<?php p($l->t('Library scan is active.')); ?>
		<?php elseif ($clusteringActive): ?>
			<?php p($l->t('Clustering is active.')); ?>
		<?php else: ?>
			<?php p($l->t('Idle - nothing running.')); ?>
		<?php endif; ?>
		</strong>
	</p>
	<p><?php p($l->n('%n file queued for detection right now', '%n files queued for detection right now', $queuedDetections, [$queuedDetections])); ?><?php if ($processingPaused && $queuedDetections > 0): ?> <?php p($l->t('(held, not shrinking, while paused)')); ?><?php endif; ?></p>
	<p><?php p($l->n('%n picture processed in the last hour', '%n pictures processed in the last hour', $processedLastHour, [$processedLastHour])); ?></p>
	<p><?php p($l->t('%s processed, %s not yet processed', [(string)$processedCount, (string)$unprocessedCount])); ?></p>
	<p><?php p($l->n('%n picture still needs to be clustered', '%n pictures still need to be clustered', $unclusteredCount, [$unclusteredCount])); ?></p>
</div>

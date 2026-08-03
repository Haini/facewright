<?php
/** @var array $_ */
$enabled = !empty($_['enabled']);
$paths = $_['paths'] ?? '';
$clusterMaxDistance = $_['clusterMaxDistance'] ?? 0.5;
$clusterMinFaces = $_['clusterMinFaces'] ?? 3;
$chunkSize = $_['chunkSize'] ?? 50;
$clusterChunkSize = $_['clusterChunkSize'] ?? 300;
$processedCount = $_['processedCount'] ?? 0;
$unprocessedCount = $_['unprocessedCount'] ?? 0;
$processedLastHour = $_['processedLastHour'] ?? 0;
$unclusteredCount = $_['unclusteredCount'] ?? 0;
$scanActive = !empty($_['scanActive']);
$clusteringActive = !empty($_['clusteringActive']);
$processingPaused = !empty($_['processingPaused']);
$automaticDetectionEnabled = !empty($_['automaticDetectionEnabled']);
?>
<div id="facewright-personal-enabled-settings" class="section">
	<h2><?php p($l->t('Facewright')); ?></h2>
	<p><?php p($l->t('Finds faces in your photos and groups them into people, so they show up on the People page. Nothing is looked at until you turn this on.')); ?></p>
	<p>
		<input type="checkbox" id="facewright-personal-enabled" class="checkbox" <?php p($enabled ? 'checked' : ''); ?>>
		<label for="facewright-personal-enabled"><?php p($l->t('Find faces in my photos')); ?></label>
	</p>
	<p><?php p($l->t('Turning this off stops any further scanning of your files. Faces already found are kept - nothing is deleted.')); ?></p>
	<p id="facewright-personal-enabled-status"></p>
</div>
<div id="facewright-personal-paths-settings" class="section">
	<h2><?php p($l->t('Folders to look at')); ?></h2>
	<p><?php p($l->t('One folder per line, relative to the root of your files - for example "Photos" or "Camera/2024". Everything outside them is ignored. Leave this empty to include all of your files.')); ?></p>
	<p>
		<textarea id="facewright-personal-paths" rows="6" cols="50" placeholder="Photos&#10;Camera/2024"><?php p($paths); ?></textarea>
	</p>
	<p>
		<button type="button" id="facewright-personal-paths-save"><?php p($l->t('Save')); ?></button>
	</p>
	<p><?php p($l->t('Removing a folder here stops future scanning of it. Faces already found in it stay where they are.')); ?></p>
	<p id="facewright-personal-paths-status"></p>
</div>
<div id="facewright-personal-scan-settings" class="section">
	<h2><?php p($l->t('Scan my existing photos')); ?></h2>
	<p><?php p($l->t('Photos you upload from now on are picked up on their own. Use this for the photos that are already there. It works through them a chunk at a time in the background over the coming hours, so it never has to do everything at once.')); ?></p>
	<?php if (!$automaticDetectionEnabled): ?>
		<p><em><?php p($l->t('Note: automatic scanning of new uploads is currently switched off for the whole server, so only this button will find anything.')); ?></em></p>
	<?php endif; ?>
	<p>
		<label for="facewright-personal-chunk-size"><?php p($l->t('Photos per round')); ?></label>
		<input type="number" id="facewright-personal-chunk-size" min="1" value="<?php p((string)$chunkSize); ?>">
		<button type="button" id="facewright-personal-run-scan"><?php p($l->t('Scan my files now')); ?></button>
	</p>
	<p id="facewright-personal-run-scan-status"></p>
</div>
<div id="facewright-personal-clustering-settings" class="section">
	<h2><?php p($l->t('Grouping faces into people')); ?></h2>
	<p><?php p($l->t('Two faces are treated as the same person when they are closer together than the distance below, and a person is only created once that many photos of them have been found. Lower distance and more photos mean fewer, stricter groups; higher distance and fewer photos mean more groups, with more risk of two people being mixed up.')); ?></p>
	<p>
		<label for="facewright-personal-cluster-max-distance"><?php p($l->t('Max distance between two faces')); ?></label>
		<input type="number" id="facewright-personal-cluster-max-distance" min="0" max="2" step="0.01" value="<?php p((string)$clusterMaxDistance); ?>">
	</p>
	<p>
		<label for="facewright-personal-cluster-min-faces"><?php p($l->t('Photos needed to make a person')); ?></label>
		<input type="number" id="facewright-personal-cluster-min-faces" min="1" value="<?php p((string)$clusterMinFaces); ?>">
		<button type="button" id="facewright-personal-run-clustering"><?php p($l->t('Group my faces now')); ?></button>
	</p>
	<input type="hidden" id="facewright-personal-cluster-chunk-size" value="<?php p((string)$clusterChunkSize); ?>">
	<p><?php p($l->t('Changing either setting makes faces that previously did not fit anywhere eligible again, so they get a fresh chance under the new numbers. Runs in the background across upcoming minutes.')); ?></p>
	<p id="facewright-personal-run-clustering-status"></p>
</div>
<div id="facewright-personal-stats-settings" class="section">
	<h2><?php p($l->t('My status')); ?></h2>
	<p><?php p($l->t('Snapshot as of this page load - reload to refresh.')); ?></p>
	<p>
		<strong>
		<?php if (!$enabled): ?>
			<?php p($l->t('Off - Facewright is not looking at your files.')); ?>
		<?php elseif ($processingPaused): ?>
			<?php p($l->t('Paused by the server administrator.')); ?>
		<?php elseif ($scanActive && $clusteringActive): ?>
			<?php p($l->t('Scanning your photos and grouping faces.')); ?>
		<?php elseif ($scanActive): ?>
			<?php p($l->t('Scanning your photos.')); ?>
		<?php elseif ($clusteringActive): ?>
			<?php p($l->t('Grouping your faces into people.')); ?>
		<?php else: ?>
			<?php p($l->t('Idle - nothing running.')); ?>
		<?php endif; ?>
		</strong>
	</p>
	<p><?php p($l->t('%s of your photos checked, %s still to go', [(string)$processedCount, (string)$unprocessedCount])); ?></p>
	<p><?php p($l->n('%n photo checked in the last hour', '%n photos checked in the last hour', $processedLastHour, [$processedLastHour])); ?></p>
	<p><?php p($l->n('%n face still to be grouped', '%n faces still to be grouped', $unclusteredCount, [$unclusteredCount])); ?></p>
</div>

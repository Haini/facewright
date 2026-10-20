<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="facewright-people"
	data-enabled="<?php p(!empty($_['enabled']) ? '1' : '0'); ?>"
	data-min-faces="<?php p((string)($_['minFaces'] ?? 3)); ?>"
	data-settings-url="<?php p($_['settingsUrl'] ?? ''); ?>">
	<nav class="fw-nav" aria-label="<?php p($l->t('People')); ?>">
		<a class="fw-nav-link" href="#/people" data-view="people"><?php p($l->t('People')); ?></a>
		<a class="fw-nav-link" href="#/unassigned" data-view="unassigned"><?php p($l->t('Unassigned')); ?></a>
		<a class="fw-nav-link" href="#/ignored" data-view="ignored"><?php p($l->t('Ignored')); ?></a>
	</nav>
	<section class="fw-main" id="facewright-people-main" aria-live="polite"></section>
	<div class="fw-toasts" id="facewright-toasts" role="status"></div>
</div>

(function () {
	function bindToggle(checkboxId, statusId, endpoint, bodyKey) {
		var checkbox = document.getElementById(checkboxId);
		var status = document.getElementById(statusId);
		if (!checkbox) {
			return;
		}
		checkbox.addEventListener('change', function () {
			var enabled = checkbox.checked;
			var body = {};
			body[bodyKey] = enabled;
			fetch(OC.generateUrl('/apps/recognaize_bridge/' + endpoint), {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify(body),
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('Request failed');
				}
				status.textContent = enabled ? 'Enabled.' : 'Disabled.';
			}).catch(function () {
				checkbox.checked = !enabled;
				status.textContent = 'Failed to save - see the Nextcloud log.';
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		bindToggle('recognaize-people-enabled', 'recognaize-people-status', 'people-enabled', 'enabled');
		bindToggle('recognaize-automatic-detection-enabled', 'recognaize-automatic-detection-status', 'automatic-detection-enabled', 'enabled');
		bindToggle('recognaize-processing-paused', 'recognaize-processing-paused-status', 'processing-paused', 'paused');

		var pathsField = document.getElementById('recognaize-included-paths');
		var pathsSaveButton = document.getElementById('recognaize-included-paths-save');
		var pathsStatus = document.getElementById('recognaize-included-paths-status');
		if (pathsSaveButton) {
			pathsSaveButton.addEventListener('click', function (event) {
				event.preventDefault();
				fetch(OC.generateUrl('/apps/recognaize_bridge/included-paths'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ paths: pathsField.value }),
				}).then(function (response) {
					if (!response.ok) {
						throw new Error('Request failed');
					}
					return response.json();
				}).then(function (data) {
					pathsField.value = data.paths.join('\n');
					pathsStatus.textContent = 'Saved.';
				}).catch(function () {
					pathsStatus.textContent = 'Failed to save - see the Nextcloud log.';
				});
			});
		}

		var clusterMaxDistanceField = document.getElementById('recognaize-cluster-max-distance');
		var clusterMinFacesField = document.getElementById('recognaize-cluster-min-faces');
		var runClusteringButton = document.getElementById('recognaize-run-clustering');
		var runClusteringStatus = document.getElementById('recognaize-run-clustering-status');
		if (runClusteringButton) {
			runClusteringButton.addEventListener('click', function (event) {
				event.preventDefault();
				runClusteringStatus.textContent = 'Clustering...';
				fetch(OC.generateUrl('/apps/recognaize_bridge/run-clustering'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						maxDistance: parseFloat(clusterMaxDistanceField.value) || 0.5,
						minFaces: parseInt(clusterMinFacesField.value, 10) || 3,
					}),
				}).then(function (response) {
					if (!response.ok) {
						throw new Error('Request failed');
					}
					return response.json();
				}).then(function (data) {
					runClusteringStatus.textContent = data.newClusters + ' new cluster(s), ' + data.joined + ' face(s) joined an existing cluster, ' + data.skipped + ' face(s) left unclustered.';
				}).catch(function () {
					runClusteringStatus.textContent = 'Failed to run - see the Nextcloud log.';
				});
			});
		}

		var batchSizeField = document.getElementById('recognaize-batch-size');
		var runBatchButton = document.getElementById('recognaize-run-batch');
		var runBatchStatus = document.getElementById('recognaize-run-batch-status');
		if (runBatchButton) {
			runBatchButton.addEventListener('click', function (event) {
				event.preventDefault();
				runBatchStatus.textContent = 'Queuing...';
				fetch(OC.generateUrl('/apps/recognaize_bridge/run-batch'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ batchSize: parseInt(batchSizeField.value, 10) || 50 }),
				}).then(function (response) {
					if (!response.ok) {
						throw new Error('Request failed');
					}
					return response.json();
				}).then(function (data) {
					runBatchStatus.textContent = 'Queued ' + data.queued + ' file(s) for processing.';
				}).catch(function () {
					runBatchStatus.textContent = 'Failed to run - see the Nextcloud log.';
				});
			});
		}

		var runScanButton = document.getElementById('recognaize-run-scan');
		var runScanStatus = document.getElementById('recognaize-run-scan-status');
		if (runScanButton) {
			runScanButton.addEventListener('click', function (event) {
				event.preventDefault();
				runScanStatus.textContent = 'Starting...';
				fetch(OC.generateUrl('/apps/recognaize_bridge/run-scan'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ chunkSize: parseInt(batchSizeField.value, 10) || 50 }),
				}).then(function (response) {
					if (!response.ok) {
						throw new Error('Request failed');
					}
					return response.json();
				}).then(function () {
					runScanStatus.textContent = 'Started - will enqueue files in chunks across upcoming cron cycles until the whole library is covered.';
				}).catch(function () {
					runScanStatus.textContent = 'Failed to start - see the Nextcloud log.';
				});
			});
		}
	});
})();

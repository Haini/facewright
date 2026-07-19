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
			fetch(OC.generateUrl('/apps/facewright_bridge/' + endpoint), {
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
		bindToggle('facewright-people-enabled', 'facewright-people-status', 'people-enabled', 'enabled');
		bindToggle('facewright-automatic-detection-enabled', 'facewright-automatic-detection-status', 'automatic-detection-enabled', 'enabled');
		bindToggle('facewright-automatic-clustering-enabled', 'facewright-automatic-clustering-status', 'automatic-clustering-enabled', 'enabled');
		bindToggle('facewright-processing-paused', 'facewright-processing-paused-status', 'processing-paused', 'paused');

		var pathsField = document.getElementById('facewright-included-paths');
		var pathsSaveButton = document.getElementById('facewright-included-paths-save');
		var pathsStatus = document.getElementById('facewright-included-paths-status');
		if (pathsSaveButton) {
			pathsSaveButton.addEventListener('click', function (event) {
				event.preventDefault();
				fetch(OC.generateUrl('/apps/facewright_bridge/included-paths'), {
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

		var clusterMaxDistanceField = document.getElementById('facewright-cluster-max-distance');
		var clusterMinFacesField = document.getElementById('facewright-cluster-min-faces');
		var clusterChunkSizeField = document.getElementById('facewright-cluster-chunk-size');
		var runClusteringButton = document.getElementById('facewright-run-clustering');
		var runClusteringStatus = document.getElementById('facewright-run-clustering-status');
		if (runClusteringButton) {
			runClusteringButton.addEventListener('click', function (event) {
				event.preventDefault();
				runClusteringStatus.textContent = 'Starting...';
				fetch(OC.generateUrl('/apps/facewright_bridge/run-clustering'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						maxDistance: parseFloat(clusterMaxDistanceField.value) || 0.5,
						minFaces: parseInt(clusterMinFacesField.value, 10) || 3,
						chunkSize: parseInt(clusterChunkSizeField.value, 10) || 300,
					}),
				}).then(function (response) {
					if (!response.ok) {
						throw new Error('Request failed');
					}
					return response.json();
				}).then(function (data) {
					runClusteringStatus.textContent = 'Started for ' + data.started + ' user(s) - runs in the background across upcoming cron cycles.';
				}).catch(function () {
					runClusteringStatus.textContent = 'Failed to start - see the Nextcloud log.';
				});
			});
		}

		var batchSizeField = document.getElementById('facewright-batch-size');
		var runBatchButton = document.getElementById('facewright-run-batch');
		var runBatchStatus = document.getElementById('facewright-run-batch-status');
		if (runBatchButton) {
			runBatchButton.addEventListener('click', function (event) {
				event.preventDefault();
				runBatchStatus.textContent = 'Queuing...';
				fetch(OC.generateUrl('/apps/facewright_bridge/run-batch'), {
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

		var runScanButton = document.getElementById('facewright-run-scan');
		var runScanStatus = document.getElementById('facewright-run-scan-status');
		if (runScanButton) {
			runScanButton.addEventListener('click', function (event) {
				event.preventDefault();
				runScanStatus.textContent = 'Starting...';
				fetch(OC.generateUrl('/apps/facewright_bridge/run-scan'), {
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

		var mergeUserField = document.getElementById('facewright-merge-user');
		var mergeKeepField = document.getElementById('facewright-merge-keep');
		var mergeIdField = document.getElementById('facewright-merge-id');
		var mergeButton = document.getElementById('facewright-merge-clusters');
		var mergeStatus = document.getElementById('facewright-merge-clusters-status');
		if (mergeButton) {
			mergeButton.addEventListener('click', function (event) {
				event.preventDefault();
				var userId = mergeUserField.value.trim();
				var keep = mergeKeepField.value.trim();
				var merge = mergeIdField.value.trim();
				if (!userId || !keep || !merge) {
					mergeStatus.textContent = 'Fill in the user and both people (name or cluster ID).';
					return;
				}
				if (!window.confirm('Merge "' + merge + '" into "' + keep + '" for user ' + userId + '? "' + merge + '" will be deleted.')) {
					return;
				}
				mergeStatus.textContent = 'Merging...';
				fetch(OC.generateUrl('/apps/facewright_bridge/merge-clusters'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ userId: userId, keep: keep, merge: merge }),
				}).then(function (response) {
					return response.json().then(function (data) {
						if (!response.ok) {
							throw new Error(data.error || 'Request failed');
						}
						return data;
					});
				}).then(function (data) {
					mergeStatus.textContent = 'Moved ' + data.moved + ' face(s) into "' + keep + '". "' + merge + '" deleted.';
				}).catch(function (err) {
					mergeStatus.textContent = 'Failed: ' + err.message;
				});
			});
		}
	});
})();

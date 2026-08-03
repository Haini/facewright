(function () {
	function put(endpoint, body) {
		return fetch(OC.generateUrl('/apps/facewright_bridge/' + endpoint), {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify(body),
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (data) {
				if (!response.ok) {
					throw new Error(data.error || 'Request failed');
				}
				return data;
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var enabledBox = document.getElementById('facewright-personal-enabled');
		var enabledStatus = document.getElementById('facewright-personal-enabled-status');
		if (enabledBox) {
			enabledBox.addEventListener('change', function () {
				var enabled = enabledBox.checked;
				put('personal/enabled', { enabled: enabled }).then(function () {
					enabledStatus.textContent = enabled
						? 'On. Use "Scan my files now" below to go through the photos you already have.'
						: 'Off. Nothing new will be scanned; faces already found are kept.';
				}).catch(function (err) {
					// Put the checkbox back, so what is on screen keeps matching what is stored.
					enabledBox.checked = !enabled;
					enabledStatus.textContent = 'Failed to save: ' + err.message;
				});
			});
		}

		var pathsField = document.getElementById('facewright-personal-paths');
		var pathsSaveButton = document.getElementById('facewright-personal-paths-save');
		var pathsStatus = document.getElementById('facewright-personal-paths-status');
		if (pathsSaveButton) {
			// type="button" in the template plus preventDefault here: the settings page wraps
			// sections in a form, and a default-type button submits it in parallel with this
			// request, which can abort the save mid-flight and look like it silently reverted.
			pathsSaveButton.addEventListener('click', function (event) {
				event.preventDefault();
				put('personal/included-paths', { paths: pathsField.value }).then(function (data) {
					pathsField.value = data.paths.join('\n');
					pathsStatus.textContent = data.paths.length
						? 'Saved.'
						: 'Saved - with no folders listed, all of your files are included.';
				}).catch(function (err) {
					pathsStatus.textContent = 'Failed to save: ' + err.message;
				});
			});
		}

		var chunkSizeField = document.getElementById('facewright-personal-chunk-size');
		var runScanButton = document.getElementById('facewright-personal-run-scan');
		var runScanStatus = document.getElementById('facewright-personal-run-scan-status');
		if (runScanButton) {
			runScanButton.addEventListener('click', function (event) {
				event.preventDefault();
				runScanStatus.textContent = 'Starting...';
				put('personal/run-scan', { chunkSize: parseInt(chunkSizeField.value, 10) || 50 }).then(function () {
					runScanStatus.textContent = 'Started - your photos will be worked through in the background. Reload this page to see progress.';
				}).catch(function (err) {
					runScanStatus.textContent = 'Failed to start: ' + err.message;
				});
			});
		}

		var maxDistanceField = document.getElementById('facewright-personal-cluster-max-distance');
		var minFacesField = document.getElementById('facewright-personal-cluster-min-faces');
		var clusterChunkSizeField = document.getElementById('facewright-personal-cluster-chunk-size');
		var runClusteringButton = document.getElementById('facewright-personal-run-clustering');
		var runClusteringStatus = document.getElementById('facewright-personal-run-clustering-status');
		if (runClusteringButton) {
			runClusteringButton.addEventListener('click', function (event) {
				event.preventDefault();
				runClusteringStatus.textContent = 'Starting...';
				put('personal/run-clustering', {
					maxDistance: parseFloat(maxDistanceField.value) || 0.5,
					minFaces: parseInt(minFacesField.value, 10) || 3,
					chunkSize: parseInt(clusterChunkSizeField.value, 10) || 300,
				}).then(function (data) {
					var message = data.started
						? 'Started - runs in the background.'
						: 'Already running - nothing more to start.';
					if (data.reopened) {
						message += ' ' + data.reopened + ' face(s) will be reconsidered under the new settings.';
					}
					runClusteringStatus.textContent = message;
				}).catch(function (err) {
					runClusteringStatus.textContent = 'Failed to start: ' + err.message;
				});
			});
		}
	});
})();

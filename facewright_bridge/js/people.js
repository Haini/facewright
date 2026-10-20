(function () {
	'use strict';

	var root = document.getElementById('facewright-people');
	if (!root) {
		return;
	}
	var main = document.getElementById('facewright-people-main');
	var config = {
		enabled: root.dataset.enabled === '1',
		minFaces: parseInt(root.dataset.minFaces, 10) || 3,
		settingsUrl: root.dataset.settingsUrl || '',
	};

	// ---- helpers -------------------------------------------------------------------------

	/** Builds an element. Text only ever goes in as textContent - names are user input. */
	function h(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === false || value === null || value === undefined) {
				return;
			}
			if (key === 'class') {
				node.className = value;
			} else if (key === 'text') {
				node.textContent = value;
			} else if (key.slice(0, 2) === 'on') {
				node.addEventListener(key.slice(2), value);
			} else {
				node.setAttribute(key, value === true ? '' : value);
			}
		});
		[].concat(children || []).forEach(function (child) {
			if (child !== null && child !== undefined && child !== false) {
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			}
		});
		return node;
	}

	function clear(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function query(params) {
		var parts = Object.keys(params || {}).filter(function (key) {
			return params[key] !== undefined && params[key] !== null && params[key] !== '';
		}).map(function (key) {
			return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
		});
		return parts.length ? '?' + parts.join('&') : '';
	}

	var OCS_BASE = OC.getRootPath() + '/ocs/v2.php/apps/facewright_bridge/api/v1';

	/** One OCS call; resolves with the unwrapped `ocs.data`, rejects with Error(message, status). */
	function ocs(method, path, body) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'OCS-APIRequest': 'true', requesttoken: OC.requestToken, Accept: 'application/json' },
		};
		if (body !== undefined) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(body);
		}
		return fetch(OCS_BASE + path, options).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (json) {
				var data = json.ocs && json.ocs.data !== undefined ? json.ocs.data : {};
				if (!response.ok) {
					var error = new Error((data && data.message) || 'Request failed');
					error.status = response.status;
					throw error;
				}
				return data;
			});
		});
	}

	function previewUrl(faceId, size) {
		return OC.generateUrl('/apps/facewright_bridge/faces/' + faceId + '/preview') + '?size=' + size;
	}

	function toast(message, isError) {
		var node = h('div', { class: 'fw-toast' + (isError ? ' error' : ''), text: message });
		document.getElementById('facewright-toasts').appendChild(node);
		setTimeout(function () {
			node.remove();
		}, isError ? 8000 : 4000);
	}

	function fail(error) {
		toast(error && error.message ? error.message : 'Something went wrong', true);
	}

	// ---- views ---------------------------------------------------------------------------

	var views = {
		people: function () {
			main.appendChild(h('h2', { text: 'People' }));
		},
		person: function () {
			main.appendChild(h('h2', { text: 'Person' }));
		},
		unassigned: function () {
			main.appendChild(h('h2', { text: 'Unassigned' }));
		},
		ignored: function () {
			main.appendChild(h('h2', { text: 'Ignored' }));
		},
	};

	function renderDisabled() {
		main.appendChild(h('div', { class: 'fw-empty' }, [
			h('p', { text: 'Facewright is not looking at your photos yet, so there are no people to show.' }),
			h('p', {}, [h('a', { href: config.settingsUrl, text: 'Turn it on in your personal settings' })]),
		]));
	}

	// ---- router --------------------------------------------------------------------------

	/** '#/person/12' -> {name: 'person', args: ['12']}; anything unknown is the people list. */
	function parseRoute() {
		var parts = (location.hash || '').replace(/^#\/?/, '').split('/').filter(Boolean);
		var name = parts[0] || 'people';
		if (!Object.prototype.hasOwnProperty.call(views, name)) {
			name = 'people';
			parts = [];
		}
		return { name: name, args: parts.slice(1) };
	}

	function render() {
		var route = parseRoute();
		[].forEach.call(root.querySelectorAll('.fw-nav-link'), function (link) {
			// The person page belongs under People in the navigation.
			var active = link.dataset.view === (route.name === 'person' ? 'people' : route.name);
			link.classList.toggle('active', active);
			if (active) {
				link.setAttribute('aria-current', 'page');
			} else {
				link.removeAttribute('aria-current');
			}
		});
		clear(main);
		main.scrollTop = 0;
		if (!config.enabled) {
			renderDisabled();
			return;
		}
		views[route.name].apply(null, route.args);
	}

	window.addEventListener('hashchange', render);
	render();
})();

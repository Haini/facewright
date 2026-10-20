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

	/** localStorage is a per-viewer convenience: it can be missing, full or blocked. */
	function readStored(key, fallback) {
		try {
			var value = window.localStorage.getItem('facewright.' + key);
			return value === null ? fallback : value;
		} catch (e) {
			return fallback;
		}
	}

	function writeStored(key, value) {
		try {
			window.localStorage.setItem('facewright.' + key, String(value));
		} catch (e) {
			// Not remembering the setting is fine.
		}
	}

	function debounce(fn, ms) {
		var timer = null;
		return function () {
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(null, args);
			}, ms);
		};
	}

	/**
	 * Calls load() whenever the scroll container is within ~600px of its end and load() isn't
	 * already running. Returns a stop function, for the view's teardown.
	 */
	function infiniteScroll(load) {
		var scheduled = false;
		function check() {
			scheduled = false;
			if (main.scrollHeight - main.scrollTop - main.clientHeight < 600) {
				load();
			}
		}
		function onScroll() {
			if (!scheduled) {
				scheduled = true;
				requestAnimationFrame(check);
			}
		}
		main.addEventListener('scroll', onScroll);
		window.addEventListener('resize', onScroll);
		return {
			check: onScroll,
			stop: function () {
				main.removeEventListener('scroll', onScroll);
				window.removeEventListener('resize', onScroll);
			},
		};
	}

	function plural(count, one, many) {
		return count.toLocaleString() + ' ' + (count === 1 ? one : many);
	}

	// ---- people list ---------------------------------------------------------------------

	var PEOPLE_PAGE = 60;

	function personTile(person) {
		var named = person.title !== '';
		var picture = person.cover
			? h('img', { class: 'fw-crop', src: previewUrl(person.cover, 256), loading: 'lazy', alt: '', draggable: 'false' })
			: h('div', { class: 'fw-crop fw-crop-empty' });
		return h('a', { class: 'fw-person', href: '#/person/' + person.id }, [
			picture,
			h('span', { class: 'fw-person-name' + (named ? '' : ' unnamed'), text: named ? person.title : 'Unnamed' }),
			h('span', { class: 'fw-person-count', text: plural(person.count, 'face', 'faces') }),
		]);
	}

	function peopleView() {
		var state = { generation: 0, offset: 0, total: null, loading: false, done: false };
		var minCount = Math.max(0, parseInt(readStored('minCount', config.minFaces), 10) || 0);
		var search = '';

		var totalNode = h('span', { class: 'fw-total' });
		var searchInput = h('input', { type: 'search', class: 'fw-search', placeholder: 'Search by name', 'aria-label': 'Search people by name' });
		var minInput = h('input', { type: 'number', min: '0', class: 'fw-min', value: String(minCount), 'aria-label': 'Minimum number of faces for unnamed people' });
		var grid = h('div', { class: 'fw-grid fw-people-grid' });
		var message = h('div', { class: 'fw-empty' });

		main.appendChild(h('div', { class: 'fw-toolbar' }, [
			h('h2', { text: 'People' }),
			totalNode,
			h('span', { class: 'fw-spacer' }),
			searchInput,
			h('label', { class: 'fw-min-label' }, ['Hide unnamed people with fewer than ', minInput, ' faces']),
		]));
		main.appendChild(grid);
		main.appendChild(message);

		var scroller = infiniteScroll(loadMore);

		function loadMore() {
			if (state.loading || state.done) {
				return;
			}
			state.loading = true;
			var generation = state.generation;
			ocs('GET', '/people' + query({ limit: PEOPLE_PAGE, offset: state.offset, minCount: minCount, search: search })).then(function (data) {
				if (generation !== state.generation) {
					return; // a filter changed while this was in flight
				}
				state.loading = false;
				data.items.forEach(function (person) {
					grid.appendChild(personTile(person));
				});
				state.offset += data.items.length;
				state.total = data.total;
				state.done = data.items.length < PEOPLE_PAGE || state.offset >= data.total;
				totalNode.textContent = plural(data.total, 'person', 'people');
				message.textContent = '';
				if (data.total === 0) {
					message.textContent = search !== '' || minCount > 0
						? 'No people match. Try a lower minimum or a different name.'
						: 'No people yet. They appear here once your photos have been scanned and grouped.';
				}
				scroller.check();
			}).catch(function (error) {
				if (generation === state.generation) {
					state.loading = false;
					fail(error);
				}
			});
		}

		function reset() {
			state.generation++;
			state.offset = 0;
			state.done = false;
			state.loading = false;
			clear(grid);
			message.textContent = '';
			loadMore();
		}

		searchInput.addEventListener('input', debounce(function () {
			search = searchInput.value.trim();
			reset();
		}, 300));
		minInput.addEventListener('input', debounce(function () {
			minCount = Math.max(0, parseInt(minInput.value, 10) || 0);
			writeStored('minCount', minCount);
			reset();
		}, 400));

		loadMore();
		return scroller.stop;
	}

	// ---- views ---------------------------------------------------------------------------

	var views = {
		people: peopleView,
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

	var teardown = null;

	function render() {
		if (teardown) {
			teardown();
			teardown = null;
		}
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
		teardown = views[route.name].apply(null, route.args) || null;
	}

	window.addEventListener('hashchange', render);
	render();
})();

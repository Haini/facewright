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
					error.code = data && data.code;
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

	// ---- person picker -------------------------------------------------------------------

	/**
	 * A modal "choose a person" dialog. Resolves with {clusterId} for an existing person,
	 * {newTitle} when the user types a new name (only if options.allowNew), or null if cancelled.
	 * options.exclude is a person id to leave out, normally the one the faces come from.
	 */
	function pickPerson(options) {
		return new Promise(function (resolve) {
			var finished = false;
			var generation = 0;
			var input = h('input', { type: 'search', class: 'fw-search', placeholder: 'Search people', 'aria-label': 'Search people' });
			var list = h('div', { class: 'fw-picker-list' });
			var dialog = h('dialog', { class: 'fw-dialog', 'aria-label': options.title }, [
				h('h3', { text: options.title }),
				input,
				list,
				h('div', { class: 'fw-dialog-buttons' }, [h('button', { type: 'button', text: 'Cancel', onclick: function () {
					finish(null);
				} })]),
			]);

			function finish(result) {
				if (finished) {
					return;
				}
				finished = true;
				dialog.close();
				dialog.remove();
				resolve(result);
			}

			function row(picture, name, detail, onClick, extraClass) {
				return h('button', { type: 'button', class: 'fw-picker-row' + (extraClass ? ' ' + extraClass : ''), onclick: onClick }, [
					picture,
					h('span', { class: 'fw-picker-name', text: name }),
					h('span', { class: 'fw-person-count', text: detail }),
				]);
			}

			function refresh() {
				var term = input.value.trim();
				var current = ++generation;
				ocs('GET', '/people' + query({ limit: 20, search: term })).then(function (data) {
					if (current !== generation || finished) {
						return;
					}
					clear(list);
					var exact = data.items.some(function (person) {
						return person.title === term;
					});
					// Offering a name that is already taken would only end in an error.
					if (options.allowNew && term !== '' && !exact) {
						list.appendChild(row(h('span', { class: 'fw-picker-plus', text: '+' }), 'New person named “' + term + '”', '', function () {
							finish({ newTitle: term });
						}, 'fw-picker-new'));
					}
					data.items.filter(function (person) {
						return person.id !== options.exclude;
					}).forEach(function (person) {
						var picture = person.cover
							? h('img', { class: 'fw-picker-crop', src: previewUrl(person.cover, 64), alt: '' })
							: h('span', { class: 'fw-picker-crop fw-crop-empty' });
						list.appendChild(row(picture, person.title !== '' ? person.title : 'Unnamed', plural(person.count, 'face', 'faces'), function () {
							finish({ clusterId: person.id });
						}));
					});
					if (!list.firstChild) {
						list.appendChild(h('p', { class: 'fw-picker-none', text: 'No people found.' }));
					}
				}).catch(function (error) {
					if (current === generation) {
						fail(error);
					}
				});
			}

			input.addEventListener('input', debounce(refresh, 200));
			// Esc, and a click on the backdrop, cancel; the browser's own close would skip finish().
			dialog.addEventListener('cancel', function (event) {
				event.preventDefault();
				finish(null);
			});
			dialog.addEventListener('click', function (event) {
				if (event.target === dialog) {
					finish(null);
				}
			});

			root.appendChild(dialog);
			dialog.showModal();
			input.focus();
			refresh();
		});
	}

	// ---- face grids with selection -------------------------------------------------------

	var FACE_PAGE = 120;

	/**
	 * A paged grid of face crops that can be selected and acted on, shared by the person page and
	 * the unassigned and ignored views.
	 *
	 * options.list(limit, offset)  -> Promise<{items, total}>
	 * options.actions              -> [{label, run(ids) -> Promise<{count, message}|null>}]; null
	 *                                 means the user backed out and nothing changed
	 * options.onTotal(total)       -> called when the count changes
	 * options.emptyText            -> shown when there are no faces
	 *
	 * Selecting: the corner mark toggles; a click toggles once anything is selected (or with
	 * Ctrl/Cmd); Shift-click selects the range from the last one touched. Every action removes the
	 * affected tiles locally rather than reloading, so the scroll position survives.
	 */
	function faceGrid(options) {
		var state = { offset: 0, total: null, loading: false, done: false, busy: false, anchor: null, generation: 0, order: [], faces: {}, tiles: {}, selected: {} };
		var grid = h('div', { class: 'fw-grid fw-face-grid' });
		var message = h('div', { class: 'fw-empty' });
		var countNode = h('span', { class: 'fw-selected-count' });
		var buttons = h('span', { class: 'fw-actionbar-buttons' });
		var bar = h('div', { class: 'fw-actionbar', role: 'toolbar', 'aria-label': 'Actions for the selected faces' }, [
			countNode,
			buttons,
			h('span', { class: 'fw-spacer' }),
			h('button', { type: 'button', class: 'fw-link-button', text: 'Select all loaded', onclick: function () {
				state.order.forEach(function (id) {
					setSelected(id, true);
				});
				updateBar();
			} }),
			h('button', { type: 'button', class: 'fw-link-button', text: 'Clear', onclick: clearSelection }),
		]);
		bar.hidden = true;

		options.actions.forEach(function (action) {
			buttons.appendChild(h('button', { type: 'button', class: 'fw-action', text: action.label, onclick: function () {
				runAction(action);
			} }));
		});

		main.appendChild(grid);
		main.appendChild(message);
		main.appendChild(bar);

		var scroller = infiniteScroll(loadMore);

		function selectedIds() {
			return state.order.filter(function (id) {
				return state.selected[id];
			});
		}

		function setSelected(id, on) {
			if (on) {
				state.selected[id] = true;
			} else {
				delete state.selected[id];
			}
			var tile = state.tiles[id];
			tile.classList.toggle('selected', on);
			tile.setAttribute('aria-checked', on ? 'true' : 'false');
		}

		function updateBar() {
			var count = selectedIds().length;
			bar.hidden = count === 0;
			grid.classList.toggle('selecting', count > 0);
			countNode.textContent = plural(count, 'face', 'faces') + ' selected';
		}

		function clearSelection() {
			state.order.forEach(function (id) {
				if (state.selected[id]) {
					setSelected(id, false);
				}
			});
			state.anchor = null;
			updateBar();
		}

		function onTileActivate(id, event) {
			var anything = selectedIds().length > 0;
			if (event.shiftKey && state.anchor !== null && state.tiles[state.anchor]) {
				var from = state.order.indexOf(state.anchor);
				var to = state.order.indexOf(id);
				state.order.slice(Math.min(from, to), Math.max(from, to) + 1).forEach(function (rangeId) {
					setSelected(rangeId, true);
				});
			} else if (anything || event.ctrlKey || event.metaKey || !options.onOpen) {
				setSelected(id, !state.selected[id]);
				state.anchor = id;
			} else {
				options.onOpen(state.faces[id]);
			}
			updateBar();
		}

		function addTile(face) {
			var tile = h('div', { class: 'fw-face', role: 'checkbox', 'aria-checked': 'false', 'aria-label': 'Face', tabindex: '0' }, [
				h('img', { class: 'fw-crop', src: previewUrl(face.id, 160), loading: 'lazy', alt: '', draggable: 'false' }),
				h('span', { class: 'fw-face-check', 'aria-hidden': 'true' }),
			]);
			tile.addEventListener('click', function (event) {
				// The corner mark always means "select", never "open".
				if (event.target.classList.contains('fw-face-check')) {
					setSelected(face.id, !state.selected[face.id]);
					state.anchor = face.id;
					updateBar();
					return;
				}
				onTileActivate(face.id, event);
			});
			tile.addEventListener('keydown', function (event) {
				if (event.key === ' ' || event.key === 'Enter') {
					event.preventDefault();
					onTileActivate(face.id, event);
				}
			});
			state.order.push(face.id);
			state.faces[face.id] = face;
			state.tiles[face.id] = tile;
			grid.appendChild(tile);
		}

		function showTotal(total) {
			state.total = total;
			message.textContent = total === 0 ? options.emptyText : '';
			if (options.onTotal) {
				options.onTotal(total);
			}
		}

		function loadMore() {
			if (state.loading || state.done) {
				return;
			}
			state.loading = true;
			var generation = state.generation;
			options.list(FACE_PAGE, state.offset).then(function (data) {
				if (generation !== state.generation) {
					return;
				}
				state.loading = false;
				data.items.forEach(addTile);
				state.offset += data.items.length;
				state.done = data.items.length < FACE_PAGE || state.offset >= data.total;
				showTotal(data.total);
				scroller.check();
			}).catch(function (error) {
				if (generation === state.generation) {
					state.loading = false;
					fail(error);
				}
			});
		}

		function reload() {
			state.generation++;
			state.offset = 0;
			state.done = false;
			state.loading = false;
			state.anchor = null;
			state.order = [];
			state.faces = {};
			state.tiles = {};
			state.selected = {};
			clear(grid);
			updateBar();
			loadMore();
		}

		function removeTiles(ids) {
			ids.forEach(function (id) {
				state.tiles[id].remove();
				delete state.tiles[id];
				delete state.faces[id];
				delete state.selected[id];
			});
			state.order = state.order.filter(function (id) {
				return state.tiles[id] !== undefined;
			});
			state.anchor = null;
			// Rows before the next page's offset just disappeared from the server's list too.
			state.offset = Math.max(0, state.offset - ids.length);
			showTotal(Math.max(0, state.total - ids.length));
			updateBar();
			if (state.order.length === 0 && !state.done) {
				loadMore();
			}
		}

		function runAction(action) {
			var ids = selectedIds();
			if (ids.length === 0 || state.busy) {
				return;
			}
			state.busy = true;
			bar.classList.add('busy');
			action.run(ids).then(function (outcome) {
				if (outcome === null) {
					return;
				}
				toast(outcome.message);
				if (outcome.count === ids.length) {
					removeTiles(ids);
				} else {
					// Something changed behind our back; show what is really there.
					reload();
				}
			}).catch(fail).then(function () {
				state.busy = false;
				bar.classList.remove('busy');
			});
		}

		loadMore();
		return { stop: scroller.stop, reload: reload };
	}

	// ---- person page ---------------------------------------------------------------------

	function personView(idText) {
		var id = parseInt(idText, 10);
		if (!id) {
			location.hash = '#/people';
			return null;
		}
		var person = null;
		var titleNode = h('h2');
		var countNode = h('span', { class: 'fw-total' });
		var renameButton = h('button', { type: 'button', class: 'fw-link-button', text: 'Rename', onclick: startRename });
		var titleRow = h('div', { class: 'fw-toolbar' }, [titleNode, renameButton, countNode]);
		var grid = null;

		main.appendChild(h('p', { class: 'fw-back' }, [h('a', { href: '#/people', text: '← People' })]));
		main.appendChild(titleRow);

		function showTitle() {
			titleNode.textContent = person.title !== '' ? person.title : 'Unnamed';
			titleNode.classList.toggle('unnamed', person.title === '');
		}

		function startRename() {
			var input = h('input', { type: 'text', class: 'fw-title-input', value: person.title, placeholder: 'Name', 'aria-label': 'Name' });
			var finished = false;
			function stop() {
				if (!finished) {
					finished = true;
					input.replaceWith(titleNode);
					renameButton.hidden = false;
				}
			}
			function save() {
				var title = input.value.trim();
				if (title === person.title) {
					stop();
					return;
				}
				ocs('PUT', '/people/' + id, { title: title }).then(function (data) {
					person.title = data.title;
					showTitle();
					stop();
					toast('Renamed.');
				}).catch(fail);
			}
			input.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					save();
				} else if (event.key === 'Escape') {
					stop();
				}
			});
			input.addEventListener('blur', function () {
				// Leaving the field without Enter abandons the edit.
				setTimeout(stop, 0);
			});
			titleNode.replaceWith(input);
			renameButton.hidden = true;
			input.focus();
			input.select();
		}

		function onTotal(total) {
			person.count = total;
			countNode.textContent = plural(total, 'face', 'faces');
			if (total === 0 && person.title === '') {
				// An untitled person left with no faces is deleted by the server.
				toast('That was the last face, so this person is gone.');
				location.hash = '#/people';
			}
		}

		function name() {
			return person.title !== '' ? person.title : 'this person';
		}

		ocs('GET', '/people/' + id).then(function (data) {
			person = data;
			showTitle();
			countNode.textContent = plural(person.count, 'face', 'faces');
			grid = faceGrid({
				list: function (limit, offset) {
					return ocs('GET', '/people/' + id + '/faces' + query({ limit: limit, offset: offset }));
				},
				onTotal: onTotal,
				emptyText: 'There are no faces in this person.',
				actions: [
					{ label: 'Move to…', run: function (ids) {
						return pickPerson({ title: 'Move ' + plural(ids.length, 'face', 'faces') + ' to', exclude: id, allowNew: true }).then(function (choice) {
							if (choice === null) {
								return null;
							}
							var body = choice.newTitle !== undefined ? { ids: ids, newTitle: choice.newTitle } : { ids: ids, clusterId: choice.clusterId };
							return ocs('POST', '/faces/move', body).then(function (result) {
								return { count: result.moved, message: 'Moved ' + plural(result.moved, 'face', 'faces') + (choice.newTitle !== undefined ? ' to ' + choice.newTitle : '') + '.' };
							});
						});
					} },
					{ label: 'Remove from person', run: function (ids) {
						return ocs('POST', '/faces/detach', { ids: ids }).then(function (result) {
							return { count: result.count, message: 'Removed ' + plural(result.count, 'face', 'faces') + ' from ' + name() + '. They are now under Unassigned.' };
						});
					} },
					{ label: 'Not a face', run: function (ids) {
						return ocs('POST', '/faces/ignore', { ids: ids }).then(function (result) {
							return { count: result.count, message: 'Marked ' + plural(result.count, 'face', 'faces') + ' as not a face.' };
						});
					} },
				],
			});
		}).catch(function (error) {
			if (error.status === 404) {
				titleRow.hidden = true;
				main.appendChild(h('div', { class: 'fw-empty' }, [
					h('p', { text: 'This person no longer exists.' }),
					h('p', {}, [h('a', { href: '#/people', text: 'Back to people' })]),
				]));
			} else {
				fail(error);
			}
		});

		return function () {
			if (grid) {
				grid.stop();
			}
		};
	}

	// ---- views ---------------------------------------------------------------------------

	var views = {
		people: peopleView,
		person: personView,
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

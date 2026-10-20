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

	/** One OCS call; resolves with the unwrapped `ocs.data`, rejects with Error(message, status, code). */
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

	/**
	 * A face crop. A crop can fail (the file was deleted or can't be decoded; the endpoint answers
	 * 404), and the browser's broken-image icon in a grid of faces looks like a bug, so a failed
	 * one becomes a neutral placeholder instead (the same one a person without a cover gets).
	 */
	function cropImg(className, faceId, size, lazy) {
		var img = h('img', { class: className, src: previewUrl(faceId, size), loading: lazy ? 'lazy' : false, alt: '', draggable: 'false' });
		img.addEventListener('error', function () {
			// Not just removing src: a sized <img> without one still draws the broken-image icon.
			img.replaceWith(h('span', { class: className + ' fw-crop-empty' }));
		});
		return img;
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

	/** How a person is named in a sentence. */
	function personLabel(person) {
		return person.title !== '' ? '“' + person.title + '”' : 'an unnamed person';
	}

	// ---- dialogs -------------------------------------------------------------------------

	var dialogStack = [];

	/**
	 * Wires a <dialog> into the page: Esc and a click on the backdrop close it, and `done` runs
	 * exactly once however it closes. Returns close(), for the dialog's own buttons.
	 *
	 * Esc is handled on the document, for the topmost dialog only, rather than left to the
	 * browser: whether a browser fires `cancel` for it varies (it was observed not to for the
	 * photo dialog), and once a button the user pressed is redrawn, focus falls back to the page
	 * where a listener on the dialog never sees the key. A native close that skipped close()
	 * would leave the element behind and `done` unrun, so `cancel` and `close` are listened to too;
	 * close() is safe to call twice.
	 */
	function showDialog(dialog, done) {
		var finished = false;
		function onKey(event) {
			if (event.key === 'Escape' && dialogStack[dialogStack.length - 1] === dialog) {
				event.preventDefault();
				close();
			}
		}
		function close() {
			if (finished) {
				return;
			}
			finished = true;
			document.removeEventListener('keydown', onKey);
			dialogStack.splice(dialogStack.indexOf(dialog), 1);
			dialog.close();
			dialog.remove();
			done();
		}
		dialog.addEventListener('cancel', function (event) {
			event.preventDefault();
			close();
		});
		dialog.addEventListener('close', close);
		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) {
				close();
			}
		});
		document.addEventListener('keydown', onKey);
		dialogStack.push(dialog);
		root.appendChild(dialog);
		dialog.showModal();
		return close;
	}

	/**
	 * A modal with a heading, some content and buttons. Resolves with the clicked button's
	 * `value`, or null if it was dismissed. buttons: [{label, value, primary, danger}].
	 */
	function openDialog(title, body, buttons) {
		return new Promise(function (resolve) {
			var result = null;
			var close = null;
			var rendered = buttons.map(function (button) {
				return h('button', {
					type: 'button',
					class: (button.primary ? 'primary' : '') + (button.danger ? ' fw-danger' : ''),
					text: button.label,
					onclick: function () {
						result = button.value;
						close();
					},
				});
			});
			var dialog = h('dialog', { class: 'fw-dialog', 'aria-label': title }, [h('h3', { text: title })].concat(body, [
				h('div', { class: 'fw-dialog-buttons' }, rendered),
			]));
			close = showDialog(dialog, function () {
				resolve(result);
			});
			(dialog.querySelector('.primary, .fw-danger') || rendered[rendered.length - 1]).focus();
		});
	}

	/** Resolves true only if the user confirmed. */
	function confirmDialog(options) {
		return openDialog(options.title, h('p', { class: 'fw-dialog-text', text: options.message }), [
			{ label: 'Cancel', value: false },
			{ label: options.confirmLabel, value: true, primary: !options.danger, danger: !!options.danger },
		]).then(function (value) {
			return value === true;
		});
	}

	/**
	 * A modal "choose a person" dialog. Resolves with {clusterId, person} for an existing person,
	 * {newTitle} when the user types a new name (only if options.allowNew), or null if cancelled.
	 * options.exclude is a person id to leave out, normally the one the faces come from.
	 */
	function pickPerson(options) {
		return new Promise(function (resolve) {
			var result = null;
			var generation = 0;
			var finished = false;
			var close = null;
			var input = h('input', { type: 'search', class: 'fw-search', placeholder: 'Search people', 'aria-label': 'Search people' });
			var list = h('div', { class: 'fw-picker-list' });
			var dialog = h('dialog', { class: 'fw-dialog', 'aria-label': options.title }, [
				h('h3', { text: options.title }),
				input,
				list,
				h('div', { class: 'fw-dialog-buttons' }, [h('button', { type: 'button', text: 'Cancel', onclick: function () {
					close();
				} })]),
			]);

			function choose(value) {
				result = value;
				close();
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
							choose({ newTitle: term });
						}, 'fw-picker-new'));
					}
					data.items.filter(function (person) {
						return person.id !== options.exclude;
					}).forEach(function (person) {
						var picture = person.cover
							? cropImg('fw-picker-crop', person.cover, 64, false)
							: h('span', { class: 'fw-picker-crop fw-crop-empty' });
						list.appendChild(row(picture, person.title !== '' ? person.title : 'Unnamed', plural(person.count, 'face', 'faces'), function () {
							choose({ clusterId: person.id, person: person });
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
			close = showDialog(dialog, function () {
				finished = true;
				resolve(result);
			});
			input.focus();
			refresh();
		});
	}

	// ---- selection -----------------------------------------------------------------------

	/**
	 * Which tiles of a grid are selected, shared by the face grids and the people list.
	 * A click toggles once anything is selected (or with Ctrl/Cmd), Shift-click selects the range
	 * from the last tile touched, and a click with nothing selected is left to the caller (open).
	 */
	function createSelection(onChange) {
		var order = [];
		var tiles = {};
		var selected = {};
		var anchor = null;

		function mark(id, on) {
			if (on) {
				selected[id] = true;
			} else {
				delete selected[id];
			}
			tiles[id].classList.toggle('selected', on);
			tiles[id].setAttribute('aria-checked', on ? 'true' : 'false');
		}

		var selection = {
			add: function (id, tile) {
				order.push(id);
				tiles[id] = tile;
			},
			remove: function (ids) {
				ids.forEach(function (id) {
					tiles[id].remove();
					delete tiles[id];
					delete selected[id];
				});
				order = order.filter(function (id) {
					return tiles[id] !== undefined;
				});
				anchor = null;
				onChange();
			},
			reset: function () {
				order = [];
				tiles = {};
				selected = {};
				anchor = null;
				onChange();
			},
			ids: function () {
				return order.filter(function (id) {
					return selected[id];
				});
			},
			all: function () {
				return order.slice();
			},
			count: function () {
				return selection.ids().length;
			},
			toggle: function (id) {
				mark(id, !selected[id]);
				anchor = id;
				onChange();
			},
			selectAll: function () {
				order.forEach(function (id) {
					mark(id, true);
				});
				onChange();
			},
			clear: function () {
				order.forEach(function (id) {
					if (selected[id]) {
						mark(id, false);
					}
				});
				anchor = null;
				onChange();
			},
			/** @return boolean whether the click was a selection gesture (false: the caller opens it) */
			activate: function (id, event) {
				if (event.shiftKey && anchor !== null && tiles[anchor]) {
					var from = order.indexOf(anchor);
					var to = order.indexOf(id);
					order.slice(Math.min(from, to), Math.max(from, to) + 1).forEach(function (rangeId) {
						mark(rangeId, true);
					});
					onChange();
					return true;
				}
				if (selection.count() > 0 || event.ctrlKey || event.metaKey) {
					selection.toggle(id);
					return true;
				}
				return false;
			},
		};
		return selection;
	}

	/** The sticky bar that appears while something is selected. */
	function createActionBar(nouns, selection) {
		var countNode = h('span', { class: 'fw-selected-count' });
		var buttons = h('span', { class: 'fw-actionbar-buttons' });
		var bar = h('div', { class: 'fw-actionbar', role: 'toolbar', 'aria-label': 'Actions for the selection' }, [
			countNode,
			buttons,
			h('span', { class: 'fw-spacer' }),
			h('button', { type: 'button', class: 'fw-link-button', text: 'Select all loaded', onclick: selection.selectAll }),
			h('button', { type: 'button', class: 'fw-link-button', text: 'Clear', onclick: selection.clear }),
		]);
		bar.hidden = true;
		return {
			element: bar,
			add: function (label, onClick) {
				var button = h('button', { type: 'button', class: 'fw-action', text: label, onclick: onClick });
				buttons.appendChild(button);
				return button;
			},
			update: function () {
				var count = selection.count();
				bar.hidden = count === 0;
				countNode.textContent = plural(count, nouns[0], nouns[1]) + ' selected';
			},
			busy: function (on) {
				bar.classList.toggle('busy', on);
			},
		};
	}

	// ---- merge and delete ----------------------------------------------------------------

	/**
	 * Merges `merging` (a list of {id, title, count}) into `keep`: shows how many faces will move,
	 * asks, then does it. Resolves {keepId, moved}, or null if the user backed out.
	 */
	function mergeFlow(keep, merging) {
		var ids = merging.map(function (person) {
			return person.id;
		});
		return ocs('POST', '/people/merge', { keep: keep.id, merge: ids, dryRun: true }).then(function (plan) {
			var droppedName = keep.title === '' && merging.some(function (person) {
				return person.title !== '';
			});
			var message = 'Move ' + plural(plan.moved, 'face', 'faces') + ' from '
				+ merging.map(personLabel).join(', ') + ' into ' + personLabel(keep)
				+ '. The merged ' + (merging.length === 1 ? 'person is' : 'people are') + ' removed and ' + personLabel(keep) + ' stays.'
				+ (droppedName ? ' ' + personLabel(keep) + ' has no name, so the name is lost.' : '');
			return confirmDialog({ title: 'Merge people', message: message, confirmLabel: 'Merge' }).then(function (yes) {
				if (!yes) {
					return null;
				}
				return ocs('POST', '/people/merge', { keep: keep.id, merge: ids }).then(function (result) {
					return { keepId: result.keepId, moved: result.moved };
				});
			});
		});
	}

	/** Deletes people after asking. Resolves {unassigned} or null if the user backed out. */
	function deleteFlow(people) {
		var faces = people.reduce(function (sum, person) {
			return sum + person.count;
		}, 0);
		var subject = people.length === 1 ? personLabel(people[0]) : plural(people.length, 'person', 'people');
		return confirmDialog({
			title: people.length === 1 ? 'Delete person' : 'Delete people',
			message: 'Delete ' + subject + '? Their ' + plural(faces, 'face', 'faces') + ' move to Unassigned. Nothing is deleted from your files.',
			confirmLabel: 'Delete',
			danger: true,
		}).then(function (yes) {
			if (!yes) {
				return null;
			}
			return ocs('POST', '/people/delete', { ids: people.map(function (person) {
				return person.id;
			}) });
		});
	}

	/** Asks which of several people to keep when merging them. Resolves a person, or null. */
	function chooseKeep(people) {
		var best = people.slice().sort(function (a, b) {
			return (b.title !== '') - (a.title !== '') || b.count - a.count;
		})[0];
		var chosen = best.id;
		var rows = people.map(function (person) {
			return h('label', { class: 'fw-radio-row' }, [
				h('input', { type: 'radio', name: 'fw-keep', value: String(person.id), checked: person.id === chosen, onchange: function () {
					chosen = person.id;
				} }),
				person.cover ? cropImg('fw-picker-crop', person.cover, 64, false) : h('span', { class: 'fw-picker-crop fw-crop-empty' }),
				h('span', { class: 'fw-picker-name', text: person.title !== '' ? person.title : 'Unnamed' }),
				h('span', { class: 'fw-person-count', text: plural(person.count, 'face', 'faces') }),
			]);
		});
		return openDialog('Merge ' + plural(people.length, 'person', 'people'), [
			h('p', { class: 'fw-dialog-text', text: 'Which one do you want to keep? The others are merged into it, and its name stays.' }),
			h('div', { class: 'fw-picker-list' }, rows),
		], [
			{ label: 'Cancel', value: false },
			{ label: 'Continue', value: true, primary: true },
		]).then(function (go) {
			return go === true ? people.filter(function (person) {
				return person.id === chosen;
			})[0] : null;
		});
	}

	// ---- one photo, with all its faces ---------------------------------------------------

	/** Where a face currently is, in words. cluster_id: a person's id, or -1 / -2 / -3 / null. */
	function faceState(face) {
		if (face.cluster_id > 0) {
			return { label: face.title !== null && face.title !== '' ? face.title : 'Unnamed', css: 'person' };
		}
		if (face.cluster_id === -1) {
			return { label: 'Unassigned', css: 'unassigned' };
		}
		if (face.cluster_id === -3) {
			return { label: 'Not a face', css: 'ignored' };
		}
		return { label: 'Not grouped yet', css: 'pending' };
	}

	/**
	 * Shows the photo a face is in with every face of the user's drawn on it. Clicking a box makes
	 * it the current face, which the buttons below act on. onChanged runs when the dialog closes
	 * if anything was changed, so the grid behind it can refresh.
	 */
	function openPhoto(face, onChanged) {
		var fileId = face.file_id;
		var current = face.id;
		var changed = false;
		var faces = [];

		var image = h('img', { class: 'fw-photo-img', alt: '', src: OC.generateUrl('/core/preview') + query({ fileId: fileId, x: 1024, y: 1024, a: 1 }) });
		var wrap = h('div', { class: 'fw-photo-wrap' }, [image]);
		var status = h('p', { class: 'fw-photo-status' });
		var actions = h('div', { class: 'fw-photo-actions' });
		var closeButton = h('button', { type: 'button', text: 'Close', onclick: function () {
			close();
		} });
		var dialog = h('dialog', { class: 'fw-dialog fw-photo', 'aria-label': 'Photo' }, [
			wrap,
			status,
			actions,
			h('div', { class: 'fw-dialog-buttons' }, [
				h('a', { class: 'button', href: OC.generateUrl('/f/' + fileId), target: '_blank', rel: 'noopener', text: 'Open in Files' }),
				closeButton,
			]),
		]);
		var close = showDialog(dialog, function () {
			if (changed && onChanged) {
				onChanged();
			}
		});
		closeButton.focus();

		image.addEventListener('error', function () {
			status.textContent = 'This photo could not be loaded.';
		});

		function currentFace() {
			return faces.filter(function (candidate) {
				return candidate.id === current;
			})[0] || null;
		}

		function draw() {
			[].forEach.call(wrap.querySelectorAll('.fw-box'), function (box) {
				box.remove();
			});
			faces.forEach(function (candidate) {
				var state = faceState(candidate);
				var box = h('button', {
					type: 'button',
					class: 'fw-box ' + state.css + (candidate.id === current ? ' current' : ''),
					'aria-label': state.label,
					'aria-pressed': candidate.id === current ? 'true' : 'false',
					onclick: function () {
						current = candidate.id;
						draw();
					},
				}, [h('span', { class: 'fw-box-label', text: state.label })]);
				box.style.left = (candidate.x * 100) + '%';
				box.style.top = (candidate.y * 100) + '%';
				box.style.width = (candidate.width * 100) + '%';
				box.style.height = (candidate.height * 100) + '%';
				wrap.appendChild(box);
			});
			drawActions();
		}

		function drawActions() {
			clear(actions);
			var target = currentFace();
			if (!target) {
				return;
			}
			var state = faceState(target);
			status.textContent = 'Selected face: ' + state.label + '.';
			var available = [moveAction(target.cluster_id > 0 ? target.cluster_id : null)];
			if (target.cluster_id > 0) {
				available.push(detachAction(state.label));
			}
			available.push(target.cluster_id === -3 ? restoreAction() : ignoreAction());
			available.forEach(function (action) {
				actions.appendChild(h('button', { type: 'button', class: 'fw-action', text: action.label, onclick: function () {
					actions.classList.add('busy');
					action.run([target.id]).then(function (outcome) {
						if (outcome !== null) {
							toast(outcome.message);
							changed = true;
							return load();
						}
					}).catch(fail).then(function () {
						actions.classList.remove('busy');
					});
				} }));
			});
		}

		function load() {
			return ocs('GET', '/files/' + fileId + '/faces').then(function (list) {
				faces = list;
				draw();
			});
		}

		load().catch(fail);
	}

	// ---- face actions shared by the grids ------------------------------------------------

	/** "Move to...": pick a person (or type a new name), then move the selected faces there. */
	function moveAction(excludeId) {
		return { label: 'Move to…', run: function (ids) {
			return pickPerson({ title: 'Move ' + plural(ids.length, 'face', 'faces') + ' to', exclude: excludeId, allowNew: true }).then(function (choice) {
				if (choice === null) {
					return null;
				}
				var body = choice.newTitle !== undefined ? { ids: ids, newTitle: choice.newTitle } : { ids: ids, clusterId: choice.clusterId };
				return ocs('POST', '/faces/move', body).then(function (result) {
					return { count: result.moved, message: 'Moved ' + plural(result.moved, 'face', 'faces') + (choice.newTitle !== undefined ? ' to ' + choice.newTitle : '') + '.' };
				});
			});
		} };
	}

	function detachAction(personName) {
		return { label: 'Remove from person', run: function (ids) {
			return ocs('POST', '/faces/detach', { ids: ids }).then(function (result) {
				return { count: result.count, message: 'Removed ' + plural(result.count, 'face', 'faces') + ' from ' + personName + '. They are now under Unassigned.' };
			});
		} };
	}

	function ignoreAction() {
		return { label: 'Not a face', run: function (ids) {
			return ocs('POST', '/faces/ignore', { ids: ids }).then(function (result) {
				return { count: result.count, message: 'Marked ' + plural(result.count, 'face', 'faces') + ' as not a face.' };
			});
		} };
	}

	function restoreAction() {
		return { label: 'Restore', run: function (ids) {
			return ocs('POST', '/faces/unignore', { ids: ids }).then(function (result) {
				return { count: result.count, message: 'Restored ' + plural(result.count, 'face', 'faces') + '. They will be considered the next time faces are grouped.' };
			});
		} };
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
	 * Clicking a face with nothing selected opens its photo; every action removes the affected
	 * tiles locally rather than reloading, so the scroll position survives.
	 */
	function faceGrid(options) {
		var state = { offset: 0, total: null, loading: false, done: false, busy: false, generation: 0, faces: {} };
		var grid = h('div', { class: 'fw-grid fw-face-grid' });
		var message = h('div', { class: 'fw-empty' });
		var selection = createSelection(function () {
			bar.update();
			grid.classList.toggle('selecting', selection.count() > 0);
		});
		var bar = createActionBar(['face', 'faces'], selection);

		options.actions.forEach(function (action) {
			bar.add(action.label, function () {
				runAction(action);
			});
		});

		main.appendChild(grid);
		main.appendChild(message);
		main.appendChild(bar.element);

		var scroller = infiniteScroll(loadMore);

		function addTile(face) {
			var tile = h('div', { class: 'fw-face', role: 'checkbox', 'aria-checked': 'false', 'aria-label': 'Face', tabindex: '0' }, [
				cropImg('fw-crop', face.id, 160, true),
				h('span', { class: 'fw-face-check', 'aria-hidden': 'true' }),
			]);
			function activate(event) {
				if (!selection.activate(face.id, event)) {
					openPhoto(face, reload);
				}
			}
			tile.addEventListener('click', function (event) {
				// The corner mark always means "select", never "open".
				if (event.target.classList.contains('fw-face-check')) {
					selection.toggle(face.id);
				} else {
					activate(event);
				}
			});
			tile.addEventListener('keydown', function (event) {
				if (event.key === ' ' || event.key === 'Enter') {
					event.preventDefault();
					activate(event);
				}
			});
			state.faces[face.id] = face;
			selection.add(face.id, tile);
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
			state.faces = {};
			clear(grid);
			selection.reset();
			loadMore();
		}

		function removeTiles(ids) {
			ids.forEach(function (id) {
				delete state.faces[id];
			});
			selection.remove(ids);
			// Rows before the next page's offset just disappeared from the server's list too.
			state.offset = Math.max(0, state.offset - ids.length);
			showTotal(Math.max(0, state.total - ids.length));
			if (selection.all().length === 0 && !state.done) {
				loadMore();
			}
		}

		function runAction(action) {
			var ids = selection.ids();
			if (ids.length === 0 || state.busy) {
				return;
			}
			state.busy = true;
			bar.busy(true);
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
				bar.busy(false);
			});
		}

		loadMore();
		return { stop: scroller.stop, reload: reload };
	}

	/** A page that is only a titled, explained, selectable grid of faces: Unassigned, Ignored. */
	function facesListView(page) {
		var totalNode = h('span', { class: 'fw-total' });
		main.appendChild(h('div', { class: 'fw-toolbar' }, [h('h2', { text: page.title }), totalNode]));
		main.appendChild(h('p', { class: 'fw-intro', text: page.intro }));
		var grid = faceGrid({
			list: function (limit, offset) {
				return ocs('GET', page.path + query({ limit: limit, offset: offset }));
			},
			onTotal: function (total) {
				totalNode.textContent = plural(total, 'face', 'faces');
			},
			emptyText: page.emptyText,
			actions: page.actions,
		});
		return grid.stop;
	}

	// ---- people list ---------------------------------------------------------------------

	var PEOPLE_PAGE = 60;

	function personTile(person) {
		var named = person.title !== '';
		var picture = person.cover
			? cropImg('fw-crop', person.cover, 256, true)
			: h('div', { class: 'fw-crop fw-crop-empty' });
		return h('a', { class: 'fw-person', href: '#/person/' + person.id, role: 'checkbox', 'aria-checked': 'false' }, [
			picture,
			h('span', { class: 'fw-face-check', 'aria-hidden': 'true' }),
			h('span', { class: 'fw-person-name' + (named ? '' : ' unnamed'), text: named ? person.title : 'Unnamed' }),
			h('span', { class: 'fw-person-count', text: plural(person.count, 'face', 'faces') }),
		]);
	}

	function peopleView() {
		var state = { generation: 0, offset: 0, total: null, loading: false, done: false, busy: false, people: {} };
		var minCount = Math.max(0, parseInt(readStored('minCount', config.minFaces), 10) || 0);
		var search = '';

		var totalNode = h('span', { class: 'fw-total' });
		var searchInput = h('input', { type: 'search', class: 'fw-search', placeholder: 'Search by name', 'aria-label': 'Search people by name' });
		var minInput = h('input', { type: 'number', min: '0', class: 'fw-min', value: String(minCount), 'aria-label': 'Minimum number of faces for unnamed people' });
		var grid = h('div', { class: 'fw-grid fw-people-grid' });
		var message = h('div', { class: 'fw-empty' });
		var selection = createSelection(function () {
			bar.update();
			mergeButton.hidden = selection.count() < 2;
			grid.classList.toggle('selecting', selection.count() > 0);
		});
		var bar = createActionBar(['person', 'people'], selection);

		function chosenPeople() {
			return selection.ids().map(function (id) {
				return state.people[id];
			});
		}

		var mergeButton = bar.add('Merge selected…', function () {
			var people = chosenPeople();
			if (people.length < 2 || state.busy) {
				return;
			}
			chooseKeep(people).then(function (keep) {
				if (keep === null) {
					return null;
				}
				return mergeFlow(keep, people.filter(function (person) {
					return person.id !== keep.id;
				})).then(function (result) {
					if (result) {
						forgetSuggestions();
						toast('Merged ' + plural(people.length, 'person', 'people') + '. ' + plural(result.moved, 'face', 'faces') + ' moved.');
						reset();
					}
				});
			}).catch(fail);
		});
		bar.add('Delete…', function () {
			var people = chosenPeople();
			if (people.length === 0 || state.busy) {
				return;
			}
			deleteFlow(people).then(function (result) {
				if (result) {
					toast('Deleted ' + plural(people.length, 'person', 'people') + '. ' + plural(result.unassigned, 'face', 'faces') + ' are now under Unassigned.');
					reset();
				}
			}).catch(fail);
		});
		mergeButton.hidden = true;

		main.appendChild(h('div', { class: 'fw-toolbar' }, [
			h('h2', { text: 'People' }),
			totalNode,
			h('span', { class: 'fw-spacer' }),
			searchInput,
			h('label', { class: 'fw-min-label' }, ['Hide unnamed people with fewer than ', minInput, ' faces']),
		]));
		main.appendChild(grid);
		main.appendChild(message);
		main.appendChild(bar.element);

		var scroller = infiniteScroll(loadMore);

		function addTile(person) {
			var tile = personTile(person);
			tile.addEventListener('click', function (event) {
				// The corner mark selects; anything else selects only once a selection exists, and
				// otherwise follows the link to the person.
				if (event.target.classList.contains('fw-face-check')) {
					event.preventDefault();
					selection.toggle(person.id);
				} else if (selection.activate(person.id, event)) {
					event.preventDefault();
				}
			});
			state.people[person.id] = person;
			selection.add(person.id, tile);
			grid.appendChild(tile);
		}

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
				data.items.forEach(addTile);
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
			state.people = {};
			clear(grid);
			selection.reset();
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

	// ---- "possibly the same person" ------------------------------------------------------

	/** person id -> Promise of its suggestions, for the life of the page; cleared by any merge or dismissal. */
	var suggestionCache = {};

	function forgetSuggestions() {
		suggestionCache = {};
	}

	function suggestionsFor(personId) {
		if (!suggestionCache[personId]) {
			suggestionCache[personId] = ocs('GET', '/people/' + personId + '/suggestions').then(function (data) {
				return data.items;
			});
			suggestionCache[personId].catch(function () {
				delete suggestionCache[personId]; // a failed lookup should be retried next time
			});
		}
		return suggestionCache[personId];
	}

	function suggestionCard(suggestion, actions) {
		var picture = suggestion.cover
			? cropImg('fw-suggest-crop', suggestion.cover, 128, true)
			: h('span', { class: 'fw-suggest-crop fw-crop-empty' });
		return h('div', { class: 'fw-suggest-card' }, [
			h('a', { class: 'fw-suggest-link', href: '#/person/' + suggestion.id }, [
				picture,
				h('span', { class: 'fw-person-name' + (suggestion.title === '' ? ' unnamed' : ''), text: suggestion.title !== '' ? suggestion.title : 'Unnamed' }),
			]),
			h('span', { class: 'fw-person-count', text: plural(suggestion.count, 'face', 'faces') + ' \u00b7 ' + Math.round((1 - suggestion.distance) * 100) + '% similar' }),
			h('div', { class: 'fw-suggest-actions' }, [
				h('button', { type: 'button', class: 'fw-action', text: 'Merge\u2026', onclick: actions.merge }),
				h('button', { type: 'button', class: 'fw-link-button', text: 'Not the same person', onclick: actions.dismiss }),
			]),
		]);
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
		var titleRow = h('div', { class: 'fw-toolbar' }, [
			titleNode,
			renameButton,
			h('button', { type: 'button', class: 'fw-link-button', text: 'Merge into…', onclick: startMerge }),
			h('button', { type: 'button', class: 'fw-link-button fw-danger-link', text: 'Delete person…', onclick: startDelete }),
			countNode,
		]);
		var grid = null;

		var suggestBox = h('section', { class: 'fw-suggest', 'aria-label': 'Possibly the same person' });
		suggestBox.hidden = true;

		main.appendChild(h('p', { class: 'fw-back' }, [h('a', { href: '#/people', text: '← People' })]));
		main.appendChild(titleRow);
		main.appendChild(suggestBox);

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
				}).catch(function (error) {
					if (error.code === 'title_exists') {
						stop();
						offerMerge(title);
					} else {
						fail(error);
					}
				});
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

		/** The name typed is another person's: probably the same person, so offer to merge. */
		function offerMerge(title) {
			ocs('GET', '/people' + query({ search: title, limit: 200 })).then(function (data) {
				var target = data.items.filter(function (candidate) {
					return candidate.title === title && candidate.id !== id;
				})[0];
				if (!target) {
					toast('A person named “' + title + '” already exists.', true);
					return null;
				}
				return confirmDialog({
					title: 'That name is already used',
					message: '“' + title + '” is another person with ' + plural(target.count, 'face', 'faces') + '. If it is the same person, you can merge them.',
					confirmLabel: 'Review merge',
				}).then(function (yes) {
					return yes ? mergeInto(target) : null;
				});
			}).catch(fail);
		}

		/** Merges this person into $target, then shows $target. */
		function mergeInto(target) {
			return mergeFlow(target, [person]).then(function (result) {
				if (result) {
					forgetSuggestions();
					toast('Merged. ' + plural(result.moved, 'face', 'faces') + ' moved into ' + personLabel(target) + '.');
					location.hash = '#/person/' + target.id;
				}
			});
		}

		function startMerge() {
			pickPerson({ title: 'Merge ' + personLabel(person) + ' into', exclude: id, allowNew: false }).then(function (choice) {
				return choice === null ? null : mergeInto(choice.person);
			}).catch(fail);
		}

		function startDelete() {
			deleteFlow([person]).then(function (result) {
				if (result) {
					toast('Deleted. ' + plural(result.unassigned, 'face', 'faces') + ' are now under Unassigned.');
					location.hash = '#/people';
				}
			}).catch(fail);
		}

		/** Fills the "possibly the same person" strip; nothing is shown if there is nobody to suggest. */
		function showSuggestions() {
			suggestBox.hidden = false;
			clear(suggestBox);
			suggestBox.appendChild(h('p', { class: 'fw-suggest-wait', text: 'Looking for people who look alike\u2026' }));
			suggestionsFor(id).then(function (items) {
				clear(suggestBox);
				if (items.length === 0) {
					suggestBox.hidden = true;
					return;
				}
				var cards = h('div', { class: 'fw-suggest-cards' });
				suggestBox.appendChild(h('h3', { text: 'Possibly the same person' }));
				suggestBox.appendChild(cards);
				items.forEach(function (suggestion) {
					var card = suggestionCard(suggestion, {
						merge: function () {
							// This person stays; the suggested one is merged into it.
							mergeFlow(person, [suggestion]).then(function (result) {
								if (result) {
									forgetSuggestions();
									toast('Merged. ' + plural(result.moved, 'face', 'faces') + ' moved into ' + personLabel(person) + '.');
									render();
								}
							}).catch(fail);
						},
						dismiss: function () {
							ocs('POST', '/suggestions/dismiss', { a: id, b: suggestion.id }).then(function () {
								forgetSuggestions();
								card.remove();
								if (!cards.firstChild) {
									suggestBox.hidden = true;
								}
							}).catch(fail);
						},
					});
					cards.appendChild(card);
				});
			}).catch(function (error) {
				suggestBox.hidden = true;
				fail(error);
			});
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

		ocs('GET', '/people/' + id).then(function (data) {
			person = data;
			showTitle();
			countNode.textContent = plural(person.count, 'face', 'faces');
			showSuggestions();
			grid = faceGrid({
				list: function (limit, offset) {
					return ocs('GET', '/people/' + id + '/faces' + query({ limit: limit, offset: offset }));
				},
				onTotal: onTotal,
				emptyText: 'There are no faces in this person.',
				actions: [
					moveAction(id),
					detachAction(person.title !== '' ? person.title : 'this person'),
					ignoreAction(),
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
			return facesListView({
				title: 'Unassigned',
				intro: 'Faces removed from a person, or left over when a person was deleted. They are not grouped automatically; move them to a person yourself.',
				path: '/people/unassigned',
				emptyText: 'No unassigned faces.',
				actions: [moveAction(null), ignoreAction()],
			});
		},
		ignored: function () {
			return facesListView({
				title: 'Ignored',
				intro: 'Faces marked as not a face. Restoring one lets automatic grouping consider it again; or move it straight to a person.',
				path: '/people/ignored',
				emptyText: 'No ignored faces.',
				actions: [moveAction(null), restoreAction()],
			});
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

/**
 * Éditeur d'arborescence des catégories - Answeb
 */
(function () {
  'use strict';

  const STORAGE_KEY = 'categorytree.expanded';
  const TOAST_DURATION = 8000;
  const HOVER_EXPAND_DELAY = 600;

  const root = document.getElementById('ct-tree');
  if (!root) {
    return;
  }

  const config = {
    dataUrl: root.dataset.dataUrl,
    moveUrl: root.dataset.moveUrl,
    editUrl: root.dataset.editUrl,
    createUrl: root.dataset.createUrl,
    placeholderId: root.dataset.placeholderId,
    canMove: root.dataset.canMove === '1' && typeof window.Sortable === 'function',
  };
  const i18n = JSON.parse(root.dataset.i18n);

  const wrapper = root.closest('.ct-tree-wrapper');
  const loader = wrapper.querySelector('.ct-loader');
  const searchInput = document.querySelector('.ct-search');
  const dialog = document.getElementById('ct-dialog');
  const toast = document.querySelector('.ct-toast');

  const state = {
    nodes: new Map(),
    children: new Map(),
    homeId: null,
    expanded: loadExpanded(),
    query: '',
    busy: false,
    sortables: [],
  };
  let toastTimer = null;
  let hoverTimer = null;
  let hoverTarget = null;

  // ---------------------------------------------------------------------------
  // Utilitaires

  function t(key, params) {
    let text = i18n[key] || key;
    Object.keys(params || {}).forEach(function (name) {
      text = text.split('%' + name + '%').join(params[name]);
    });

    return text;
  }

  function urlFor(template, id) {
    return template.split(config.placeholderId).join(String(id));
  }

  function normalize(text) {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined) {
      node.textContent = text;
    }

    return node;
  }

  function loadExpanded() {
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (raw) {
        return new Set(JSON.parse(raw).map(Number));
      }
    } catch (e) {
      // Stockage indisponible : état par défaut
    }

    return null;
  }

  function saveExpanded() {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(state.expanded)));
    } catch (e) {
      // Ignoré
    }
  }

  function childrenOf(id) {
    return state.children.get(id) || [];
  }

  function isDescendantOrSelf(candidateId, ancestorId) {
    let current = candidateId;
    while (current !== undefined && current !== null) {
      if (current === ancestorId) {
        return true;
      }
      const node = state.nodes.get(current);
      current = node ? node.parentId : null;
    }

    return false;
  }

  // ---------------------------------------------------------------------------
  // État

  function setTree(list) {
    state.nodes.clear();
    state.children.clear();
    state.homeId = null;

    list.forEach(function (node) {
      state.nodes.set(node.id, node);
      if (node.locked) {
        state.homeId = node.id;
      }
    });
    // Le serveur renvoie les frères triés : l'ordre d'insertion suffit
    list.forEach(function (node) {
      if (state.nodes.has(node.parentId)) {
        if (!state.children.has(node.parentId)) {
          state.children.set(node.parentId, []);
        }
        state.children.get(node.parentId).push(node.id);
      }
    });

    if (state.expanded === null) {
      state.expanded = new Set([state.homeId].concat(childrenOf(state.homeId)));
    }
    if (state.homeId !== null) {
      state.expanded.add(state.homeId);
    }

    render();
  }

  // ---------------------------------------------------------------------------
  // Rendu

  function render() {
    destroySortables();
    root.textContent = '';
    root.setAttribute('aria-busy', 'false');

    if (state.homeId === null) {
      root.appendChild(el('div', 'ct-empty', t('loadError')));

      return;
    }

    const filter = computeFilter();
    if (filter && filter.visible.size === 0) {
      root.appendChild(el('div', 'ct-empty', t('noResult')));

      return;
    }

    if (filter && config.canMove) {
      root.appendChild(el('p', 'ct-filter-note text-muted small', t('searchActive')));
    }

    const list = el('ul', 'ct-list ct-list--root');
    list.setAttribute('role', 'tree');
    list.appendChild(renderNode(state.homeId, filter));
    root.appendChild(list);

    root.classList.toggle('is-filtered', !!filter);
    if (config.canMove && !filter) {
      initSortables();
    }
  }

  function computeFilter() {
    const query = normalize(state.query.trim());
    if (!query) {
      return null;
    }

    const matches = new Set();
    const visible = new Set();
    state.nodes.forEach(function (node) {
      if (normalize(node.name).indexOf(query) !== -1) {
        matches.add(node.id);
        let current = node.id;
        while (state.nodes.has(current) && !visible.has(current)) {
          visible.add(current);
          current = state.nodes.get(current).parentId;
        }
      }
    });

    return { query: query, matches: matches, visible: visible };
  }

  function renderNode(id, filter) {
    const node = state.nodes.get(id);
    const kids = childrenOf(id);
    const siblings = childrenOf(node.parentId);
    const index = siblings.indexOf(id);
    const expanded = filter ? true : state.expanded.has(id);

    const li = el('li', 'ct-node');
    li.dataset.id = String(id);
    li.setAttribute('role', 'treeitem');
    li.classList.toggle('is-inactive', !node.active);
    li.classList.toggle('is-locked', node.locked);
    li.classList.toggle('is-collapsed', !expanded);
    li.classList.toggle('has-children', kids.length > 0);
    if (kids.length) {
      li.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    const row = el('div', 'ct-row');

    if (kids.length) {
      const toggle = el('button', 'ct-toggle');
      toggle.type = 'button';
      toggle.title = expanded ? t('collapse') : t('expand');
      toggle.setAttribute('aria-label', toggle.title);
      toggle.dataset.action = 'toggle';
      toggle.appendChild(el('i', 'material-icons', expanded ? 'expand_more' : 'chevron_right'));
      row.appendChild(toggle);
    } else {
      row.appendChild(el('span', 'ct-toggle ct-toggle--empty'));
    }

    if (config.canMove && !node.locked) {
      const handle = el('span', 'ct-handle');
      handle.title = t('drag');
      handle.appendChild(el('i', 'material-icons', 'drag_indicator'));
      row.appendChild(handle);
    } else if (node.locked) {
      const lock = el('span', 'ct-handle ct-handle--locked');
      lock.title = t('locked');
      lock.appendChild(el('i', 'material-icons', 'lock'));
      row.appendChild(lock);
    }

    const name = el('span', 'ct-name');
    appendHighlighted(name, node.name, filter && filter.matches.has(id) ? filter.query : null);
    row.appendChild(name);
    row.appendChild(el('span', 'ct-id', '#' + id));

    if (!node.active) {
      row.appendChild(el('span', 'badge badge-secondary ct-badge', t('disabled')));
    }

    const actions = el('span', 'ct-actions');
    if (config.canMove && !node.locked) {
      actions.appendChild(actionButton('moveUp', 'arrow_upward', index <= 0));
      actions.appendChild(actionButton('moveDown', 'arrow_downward', index >= siblings.length - 1));
      actions.appendChild(actionButton('moveTo', 'drive_file_move', false));
    }
    actions.appendChild(actionLink(urlFor(config.editUrl, id), 'edit', t('edit')));
    actions.appendChild(actionLink(urlFor(config.createUrl, id), 'add', t('addChild')));
    row.appendChild(actions);

    li.appendChild(row);

    const list = el('ul', 'ct-list');
    list.dataset.parentId = String(id);
    list.dataset.dropLabel = t('dropInside', { name: node.name });
    list.setAttribute('role', 'group');
    kids.forEach(function (childId) {
      if (!filter || filter.visible.has(childId)) {
        list.appendChild(renderNode(childId, filter));
      }
    });
    list.classList.toggle('ct-list--empty', kids.length === 0);
    li.appendChild(list);

    return li;
  }

  function appendHighlighted(container, text, query) {
    if (!query) {
      container.textContent = text;

      return;
    }
    // La recherche ignore les accents : on travaille sur le texte normalisé, de même longueur une fois les diacritiques retirés
    const plain = text.normalize('NFD').replace(/[̀-ͯ]/g, '');
    const start = plain.toLowerCase().indexOf(query);
    if (start === -1 || plain.length !== text.length) {
      container.textContent = text;

      return;
    }
    container.appendChild(document.createTextNode(text.slice(0, start)));
    container.appendChild(el('mark', null, text.slice(start, start + query.length)));
    container.appendChild(document.createTextNode(text.slice(start + query.length)));
  }

  function actionButton(action, icon, disabled) {
    const button = el('button', 'btn btn-link btn-sm ct-action');
    button.type = 'button';
    button.dataset.action = action;
    button.title = t(action);
    button.setAttribute('aria-label', t(action));
    button.disabled = disabled;
    button.appendChild(el('i', 'material-icons', icon));

    return button;
  }

  function actionLink(href, icon, label) {
    const link = el('a', 'btn btn-link btn-sm ct-action');
    link.href = href;
    link.title = label;
    link.setAttribute('aria-label', label);
    link.appendChild(el('i', 'material-icons', icon));

    return link;
  }

  // ---------------------------------------------------------------------------
  // Glisser-déposer

  function initSortables() {
    root.querySelectorAll('.ct-list:not(.ct-list--root)').forEach(function (list) {
      state.sortables.push(new window.Sortable(list, {
        group: 'categorytree',
        handle: '.ct-handle:not(.ct-handle--locked)',
        draggable: '.ct-node',
        animation: 150,
        fallbackOnBody: true,
        swapThreshold: 0.65,
        emptyInsertThreshold: 12,
        ghostClass: 'is-ghost',
        chosenClass: 'is-chosen',
        disabled: state.busy,
        onStart: function () {
          root.classList.add('is-dragging');
        },
        onMove: onDragMove,
        onEnd: onDragEnd,
      }));
    });
  }

  function destroySortables() {
    state.sortables.forEach(function (sortable) {
      sortable.destroy();
    });
    state.sortables = [];
  }

  // Survol prolongé d'une branche repliée : on la déplie pour permettre d'y déposer
  function onDragMove(evt) {
    const target = evt.related && evt.related.closest('.ct-node.is-collapsed.has-children');
    if (target === hoverTarget) {
      return true;
    }
    clearTimeout(hoverTimer);
    hoverTarget = target;
    if (target) {
      hoverTimer = setTimeout(function () {
        target.classList.remove('is-collapsed');
        target.setAttribute('aria-expanded', 'true');
        state.expanded.add(Number(target.dataset.id));
        saveExpanded();
      }, HOVER_EXPAND_DELAY);
    }

    return true;
  }

  function onDragEnd(evt) {
    clearTimeout(hoverTimer);
    hoverTarget = null;
    root.classList.remove('is-dragging');

    const id = Number(evt.item.dataset.id);
    const newParentId = Number(evt.to.dataset.parentId);
    const newIndex = evt.newDraggableIndex;
    if (evt.from === evt.to && evt.oldDraggableIndex === newIndex) {
      return;
    }

    requestMove(id, newParentId, newIndex);
  }

  // ---------------------------------------------------------------------------
  // Déplacements

  function requestMove(id, newParentId, newIndex) {
    const node = state.nodes.get(id);
    const payload = {
      categoryId: id,
      newParentId: newParentId,
      newIndex: newIndex,
      expectedParentId: node.parentId,
      expectedIndex: childrenOf(node.parentId).indexOf(id),
    };

    if (newParentId === node.parentId) {
      sendMove(payload, false);

      return;
    }

    const message = t('confirmMove', { name: node.name, parent: state.nodes.get(newParentId).name });
    openDialog(t('confirm'), el('p', null, message), t('confirm')).then(function (confirmed) {
      if (confirmed) {
        sendMove(payload, false);
      } else {
        render();
      }
    });
  }

  function sendMove(payload, isUndo) {
    setBusy(true);
    hideToast();

    fetch(config.moveUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
    }).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (data) {
        return { ok: response.ok, data: data };
      });
    }).then(function (result) {
      setBusy(false);
      if (result.ok) {
        setTree(result.data.tree);
        if (result.data.undo) {
          showToast(isUndo ? t('undone') : t('moved'), isUndo ? null : result.data.undo, false);
        }

        return;
      }

      showToast(result.data.error || result.data.message || t('loadError'), null, true);
      if (Array.isArray(result.data.tree)) {
        setTree(result.data.tree);
      } else {
        loadTree();
      }
    }).catch(function () {
      setBusy(false);
      showToast(t('networkError'), null, true);
      loadTree();
    });
  }

  function loadTree() {
    setBusy(true);
    fetch(config.dataUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !Array.isArray(data.tree)) {
          throw new Error(data.error || data.message || t('loadError'));
        }

        return data.tree;
      });
    }).then(function (tree) {
      setBusy(false);
      setTree(tree);
    }).catch(function (error) {
      setBusy(false);
      root.textContent = '';
      root.appendChild(el('div', 'ct-empty text-danger', error.message || t('loadError')));
    });
  }

  function setBusy(busy) {
    state.busy = busy;
    loader.hidden = !busy;
    wrapper.classList.toggle('is-busy', busy);
    root.setAttribute('aria-busy', busy ? 'true' : 'false');
    state.sortables.forEach(function (sortable) {
      sortable.option('disabled', busy);
    });
  }

  function openMoveToDialog(id) {
    const node = state.nodes.get(id);
    const body = el('div');
    const label = el('label', 'form-control-label', t('moveToLabel'));
    const select = el('select', 'custom-select');
    select.id = 'ct-move-to-parent';
    label.htmlFor = select.id;

    (function addOptions(parentId, depth) {
      if (isDescendantOrSelf(parentId, id)) {
        return;
      }
      if (parentId !== node.parentId) {
        const option = el('option', null, '  '.repeat(depth) + (depth ? '└ ' : '') + state.nodes.get(parentId).name);
        option.value = String(parentId);
        select.appendChild(option);
      }
      childrenOf(parentId).forEach(function (childId) {
        addOptions(childId, depth + 1);
      });
    })(state.homeId, 0);

    body.appendChild(label);
    body.appendChild(select);
    body.appendChild(el('p', 'text-muted small mt-2 mb-0', t('moveToHint')));

    openDialog(t('moveToTitle', { name: node.name }), body, t('confirm')).then(function (confirmed) {
      if (!confirmed || !select.value) {
        return;
      }
      const target = Number(select.value);
      sendMove({
        categoryId: id,
        newParentId: target,
        newIndex: childrenOf(target).length,
        expectedParentId: node.parentId,
        expectedIndex: childrenOf(node.parentId).indexOf(id),
      }, false);
    });
  }

  // ---------------------------------------------------------------------------
  // Dialogue et notification

  function openDialog(title, bodyNode, confirmLabel) {
    dialog.querySelector('.ct-dialog-title').textContent = title;
    const body = dialog.querySelector('.ct-dialog-body');
    body.textContent = '';
    body.appendChild(bodyNode);
    dialog.querySelector('[data-ct-confirm]').textContent = confirmLabel;
    dialog.querySelector('[data-ct-cancel]').textContent = t('cancel');
    dialog.returnValue = '';

    return new Promise(function (resolve) {
      dialog.addEventListener('close', function () {
        resolve(dialog.returnValue === 'confirm');
      }, { once: true });
      dialog.showModal();
    });
  }

  function showToast(message, undoPayload, isError) {
    clearTimeout(toastTimer);
    toast.querySelector('.ct-toast-text').textContent = message;
    toast.classList.toggle('is-error', isError);

    const undoButton = toast.querySelector('.ct-toast-undo');
    undoButton.hidden = !undoPayload;
    undoButton.textContent = t('undo');
    undoButton.onclick = undoPayload ? function () {
      sendMove(undoPayload, true);
    } : null;

    toast.hidden = false;
    toastTimer = setTimeout(hideToast, TOAST_DURATION);
  }

  function hideToast() {
    clearTimeout(toastTimer);
    toast.hidden = true;
    toast.querySelector('.ct-toast-undo').onclick = null;
  }

  // ---------------------------------------------------------------------------
  // Événements

  root.addEventListener('click', function (event) {
    const button = event.target.closest('button[data-action]');
    if (!button || state.busy) {
      return;
    }
    const li = button.closest('.ct-node');
    const id = Number(li.dataset.id);
    const node = state.nodes.get(id);

    switch (button.dataset.action) {
      case 'toggle':
        if (state.expanded.has(id)) {
          state.expanded.delete(id);
        } else {
          state.expanded.add(id);
        }
        saveExpanded();
        if (!state.query) {
          render();
        }
        break;
      case 'moveUp':
      case 'moveDown': {
        const index = childrenOf(node.parentId).indexOf(id);
        requestMove(id, node.parentId, index + (button.dataset.action === 'moveUp' ? -1 : 1));
        break;
      }
      case 'moveTo':
        openMoveToDialog(id);
        break;
    }
  });

  if (searchInput) {
    let searchTimer = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.query = searchInput.value;
        render();
      }, 150);
    });
  }

  document.querySelectorAll('[data-ct-expand-all], [data-ct-collapse-all]').forEach(function (button) {
    button.addEventListener('click', function () {
      const expand = button.hasAttribute('data-ct-expand-all');
      state.expanded = new Set(expand ? Array.from(state.children.keys()) : []);
      state.expanded.add(state.homeId);
      saveExpanded();
      render();
    });
  });

  loadTree();
})();

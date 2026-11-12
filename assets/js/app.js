jQuery(function($){


  // Reliable delegated search for every TaskPro table/search field.
  // Delegation keeps search working even when rows or modules are loaded dynamically.
  $(document).on('input keyup change search', '.banka-tms-search-box input[type="text"], input[id*="search"]', function(){
    const $input = $(this);
    const inputId = $input.attr('id') || '';

    // The task page has combined filters, so keep its dedicated filtering logic.
    if (inputId === 'banka-tms-task-search') {
      if (typeof applyTaskFilters === 'function') applyTaskFilters();
      return;
    }

    const query = String($input.val() || '').toLowerCase().trim();
    const $card = $input.closest('.banka-tms-card, .banka-tms-module-card');
    let $table = $card.find('table').first();

    if (!$table.length) {
      $table = $input.closest('section, main').find('table').first();
    }
    if (!$table.length) return;

    const $body = $table.find('tbody').first();
    const $rows = $body.children('tr').filter(function(){
      return !$(this).hasClass('banka-tms-no-result-row') &&
             !$(this).hasClass('banka-tms-user-no-result-row') &&
             !$(this).hasClass('banka-tms-designation-no-result-row') &&
             !$(this).hasClass('banka-tms-task-master-no-result-row') &&
             !$(this).hasClass('banka-tms-search-no-result-row');
    });

    let visible = 0;
    $rows.each(function(){
      const $row = $(this);
      const searchableText = $row.text().replace(/\s+/g, ' ').toLowerCase();
      const show = query === '' || searchableText.indexOf(query) !== -1;
      $row.toggle(show);
      if (show) visible++;
    });

    let $emptyRow = $body.children('.banka-tms-no-result-row, .banka-tms-user-no-result-row, .banka-tms-designation-no-result-row, .banka-tms-task-master-no-result-row, .banka-tms-search-no-result-row').first();
    if (!$emptyRow.length) {
      const columnCount = Math.max(1, $table.find('thead th').length);
      $emptyRow = $('<tr class="banka-tms-search-no-result-row" style="display:none"><td class="banka-tms-empty">No matching records found.</td></tr>');
      $emptyRow.find('td').attr('colspan', columnCount);
      $body.append($emptyRow);
    }
    $emptyRow.toggle(visible === 0);
  });
  function openModal(selector){ $(selector).fadeIn(120); $('body').addClass('banka-tms-modal-open'); }
  function closeModals(){ $('.banka-tms-modal').fadeOut(120); $('body').removeClass('banka-tms-modal-open'); }

  function setSidebarVisibility(collapsed, persist){
    const $shell = $('.banka-tms-shell');
    const $toggle = $('.banka-tms-sidebar-visibility-toggle');
    $shell.toggleClass('banka-tms-sidebar-collapsed', !!collapsed);
    $toggle.attr('aria-label', collapsed ? 'Show left menu' : 'Hide left menu');
    $toggle.attr('title', collapsed ? 'Show Menu' : 'Hide Menu');
    $toggle.find('.banka-tms-sidebar-toggle-icon').text(collapsed ? '›' : '‹');
    if (persist !== false) {
      try { window.localStorage.setItem('bankaTmsSidebarCollapsed', collapsed ? '1' : '0'); } catch(e) {}
    }
  }

  let sidebarInitiallyCollapsed = false;
  try { sidebarInitiallyCollapsed = window.localStorage.getItem('bankaTmsSidebarCollapsed') === '1'; } catch(e) {}
  if ($(window).width() > 760) setSidebarVisibility(sidebarInitiallyCollapsed, false);

  // Preserve the left-menu scroll position across page navigation.
  // The navigation list itself is the scrollable element, not the full sidebar.
  const $sidebarNav = $('.banka-tms-nav').first();
  const sidebarScrollStorageKey = 'bankaTmsSidebarNavScrollTop';
  let sidebarScrollSaveTimer = null;

  function saveSidebarScrollPosition(){
    if (!$sidebarNav.length) return;
    try {
      window.sessionStorage.setItem(sidebarScrollStorageKey, String(Math.max(0, $sidebarNav.scrollTop() || 0)));
    } catch(e) {}
  }

  function restoreSidebarScrollPosition(){
    if (!$sidebarNav.length) return;
    let savedPosition = null;
    try {
      const stored = window.sessionStorage.getItem(sidebarScrollStorageKey);
      if (stored !== null && stored !== '') savedPosition = parseInt(stored, 10);
    } catch(e) {}

    if (Number.isFinite(savedPosition)) {
      $sidebarNav.scrollTop(Math.max(0, savedPosition));
    }

    // If the selected item is still outside the visible menu area, bring only
    // that item into view without forcing the navigation back to the top.
    const $activeItem = $sidebarNav.find('.banka-tms-nav-item.active').first();
    if ($activeItem.length) {
      const navTop = $sidebarNav.offset().top;
      const navBottom = navTop + $sidebarNav.innerHeight();
      const itemTop = $activeItem.offset().top;
      const itemBottom = itemTop + $activeItem.outerHeight();
      if (itemTop < navTop || itemBottom > navBottom) {
        const target = $sidebarNav.scrollTop() + itemTop - navTop - Math.max(0, ($sidebarNav.innerHeight() - $activeItem.outerHeight()) / 2);
        $sidebarNav.scrollTop(Math.max(0, target));
        saveSidebarScrollPosition();
      }
    }
  }

  if ($sidebarNav.length) {
    // Restore after the browser and fonts finish the first layout pass.
    window.requestAnimationFrame(function(){
      window.requestAnimationFrame(restoreSidebarScrollPosition);
    });

    $sidebarNav.on('scroll', function(){
      window.clearTimeout(sidebarScrollSaveTimer);
      sidebarScrollSaveTimer = window.setTimeout(saveSidebarScrollPosition, 80);
    });

    // Save before following any left-menu link.
    $sidebarNav.on('click', 'a.banka-tms-nav-item', saveSidebarScrollPosition);
    $(window).on('pagehide beforeunload', saveSidebarScrollPosition);
  }

  $('.banka-tms-sidebar-visibility-toggle').on('click', function(){
    setSidebarVisibility(!$('.banka-tms-shell').hasClass('banka-tms-sidebar-collapsed'));
  });
  $('.banka-tms-menu-toggle').on('click', function(){ $('#banka-tms-sidebar').addClass('open'); });
  $('.banka-tms-close-sidebar').on('click', function(){ $('#banka-tms-sidebar').removeClass('open'); });
  $(window).on('resize', function(){
    if ($(window).width() <= 760) {
      $('.banka-tms-shell').removeClass('banka-tms-sidebar-collapsed');
    } else {
      let collapsed = false;
      try { collapsed = window.localStorage.getItem('bankaTmsSidebarCollapsed') === '1'; } catch(e) {}
      setSidebarVisibility(collapsed, false);
      $('#banka-tms-sidebar').removeClass('open');
    }
  });

  $(document).on('click', function(e){
    if ($(window).width() <= 760 && !$(e.target).closest('#banka-tms-sidebar,.banka-tms-menu-toggle').length) {
      $('#banka-tms-sidebar').removeClass('open');
    }
  });

  $(document).on('click', '[data-close-modal], .banka-tms-modal-backdrop', function(){ closeModals(); });

  function forceModernTaskModal(){
    const $modal = $('#banka-tms-task-modal');
    if (!$modal.length) return;

    const currentTitle = ($modal.find('.banka-tms-modal-title').text() || '').trim();
    const hasOldFields = $modal.find('#task-title').length && !$modal.find('#task-priority').length;
    if (!hasOldFields && currentTitle === 'Add Task') return;

    const $oldAssignee = $modal.find('#task-assignee-id').first();
    let assigneeOptions = $oldAssignee.length ? $oldAssignee.html() : '<option value="0">Select Assignees...</option>';
    assigneeOptions = assigneeOptions.replace(/Select assignee/gi, 'Select Assignees...');

    const ownerOptions = assigneeOptions.replace(/Select Assignees\.\.\./gi, 'Select Task Owner...');
    const categoryOptions = '<option value="">Select Category...</option>' + ((bankaTmsApp.categories || []).map(function(name){ return '<option value="' + $('<div>').text(name).html() + '">' + $('<div>').text(name).html() + '</option>'; }).join(''));
    const taskMasterOptions = '<option value="">Select a task...</option>' + ((bankaTmsApp.taskMasters || []).map(function(name){ return '<option value="' + $('<div>').text(name).html() + '">' + $('<div>').text(name).html() + '</option>'; }).join(''));
    const projectOptions = '<option value="">Select Project...</option>' + ((bankaTmsApp.projects || []).map(function(name){ return '<option value="' + $('<div>').text(name).html() + '">' + $('<div>').text(name).html() + '</option>'; }).join(''));

    const modalHtml = `
      <div class="banka-tms-modal-backdrop"></div>
      <div class="banka-tms-modal-card banka-tms-modal-card-wide banka-tms-task-modal-card-exact">
        <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
        <h2 class="banka-tms-modal-title" id="banka-tms-task-modal-title">Add Task</h2>
        <form id="banka-tms-task-form" class="banka-tms-form banka-tms-popup-form banka-tms-task-form-grid banka-tms-task-form-grid-exact">
          <input type="hidden" id="task-id" value="0" />
          <input type="hidden" id="task-status" value="Pending" />

          <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field banka-tms-task-field-task">
            <label>
              <span>Task</span>
              <select id="task-title" required>${taskMasterOptions}</select>
            </label>
            <button type="button" class="banka-tms-inline-plus" id="banka-tms-quick-add-task-master" aria-label="Add task to Task Master">+</button>
          </div>

          <label class="banka-tms-field-group banka-tms-task-field">
            <span>Priority <em>*</em></span>
            <select id="task-priority">
              <option value="High">High</option>
              <option value="Medium" selected>Medium</option>
              <option value="Low">Low</option>
            </select>
          </label>

          <label class="banka-tms-field-group banka-tms-task-field">
            <span>Assignees <em>*</em></span>
            <select id="task-assignee-id">${assigneeOptions}</select>
          </label>

          <label class="banka-tms-field-group banka-tms-task-field">
            <span>Task Owners <em>*</em></span>
            <select id="task-owner-id">${ownerOptions}</select>
          </label>

          <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field">
            <label>
              <span>Category <em>*</em></span>
              <select id="task-category">${categoryOptions}</select>
            </label>
            <button type="button" class="banka-tms-inline-plus banka-tms-quick-add-category" aria-label="Add category">+</button>
          </div>

          <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field">
            <label>
              <span>Project <em>*</em></span>
              <select id="task-project">${projectOptions}</select>
            </label>
            <button type="button" class="banka-tms-inline-plus banka-tms-quick-add-project" aria-label="Add project">+</button>
          </div>

          <label class="banka-tms-field-group banka-tms-field-half banka-tms-task-field banka-tms-task-due-date-field">
            <span>Due Date <em>*</em></span>
            <span class="banka-tms-date-input-wrap">
              <input type="date" id="task-due-date" />
              <button type="button" class="banka-tms-calendar-trigger" aria-label="Open calendar" title="Open calendar">📅</button>
            </span>
          </label>
          <label class="banka-tms-field-group banka-tms-field-half banka-tms-task-field banka-tms-task-status-display is-hidden" id="banka-tms-task-status-field">
            <span>Status</span>
            <input type="text" id="task-status-display" value="" readonly />
          </label>

          <label class="banka-tms-field-group banka-tms-field-full banka-tms-task-field">
            <span>Notes</span>
            <textarea id="task-description"></textarea>
          </label>

          <div id="banka-tms-task-status" class="banka-tms-status banka-tms-field-full"></div>
          <div class="banka-tms-modal-actions banka-tms-field-full">
            <button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button>
            <button type="submit" class="banka-tms-primary-btn" id="banka-tms-task-submit-btn">Create Task</button>
          </div>
        </form>
      </div>`;

    $modal.html(modalHtml);
  }

  forceModernTaskModal();

  function resetTaskForm(){
    $('#task-id').val('0');
    $('#task-title').val('');
    $('#task-description').val('');
    $('#task-status').val('Pending');
    $('#task-assignee-id').val('0');
    $('#task-priority').val('Medium');
    $('#task-category').val('');
    $('#task-project').val('');
    $('#task-due-date').val('');
    $('#task-owner-id').val($('#task-owner-id option:first').val());
    $('#task-status-display').val('');
    $('#banka-tms-task-status-field').addClass('is-hidden');
    $('#banka-tms-task-status').text('');
    $('#banka-tms-task-modal-title').text('Add Task');
    $('#banka-tms-task-submit-btn').text('Create Task');
  }

  function fillTaskFormFromRow($row){
    $('#task-id').val($row.data('id'));
    $('#task-title').val($row.data('title') || '');
    $('#task-description').val($row.data('description') || '');
    $('#task-status').val($row.data('status') || 'Pending');
    $('#task-assignee-id').val(($row.data('assigneeid') || 0).toString()).trigger('change');
    $('#task-priority').val(($row.data('priority') || 'Medium').toString()).trigger('change');
    $('#task-category').val(($row.data('category') || '').toString()).trigger('change');
    $('#task-project').val(($row.data('project') || '').toString()).trigger('change');
    $('#task-due-date').val(($row.data('duedate') || '').toString());
    const ownerId = ($row.data('ownerid') || '').toString();
    if (ownerId) $('#task-owner-id').val(ownerId).trigger('change');
    const rawStatus = ($row.data('status') || 'Pending').toString();
    const displayStatus = rawStatus === 'Pending' ? 'Not Yet Started' : rawStatus;
    $('#task-status-display').val(displayStatus);
    $('#banka-tms-task-status-field').removeClass('is-hidden');
    $('#banka-tms-task-status').text('');
    $('#banka-tms-task-modal-title').text('Edit Task');
    $('#banka-tms-task-submit-btn').text('Save Changes');
  }

  function formatDisplayDate(value, includeTime){
    const raw=(value||'').toString().trim();
    if(!raw) return '';
    const match=raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?/);
    if(!match) return raw;
    const date=match[3]+'/'+match[2]+'/'+match[1];
    return includeTime && match[4] ? date+' '+match[4]+':'+match[5] : date;
  }

  function fillTaskDetailFromRow($row){
    $('#banka-tms-detail-title').text($row.data('title') || '');
    $('#banka-tms-detail-status').text(($row.find('.banka-tms-status-pill').text() || '').trim());
    $('#banka-tms-detail-assignee').text($row.data('assignee') || '—');
    $('#banka-tms-detail-owner').text($row.data('owner') || '—');
    $('#banka-tms-detail-category').text($row.data('category') || '—');
    $('#banka-tms-detail-project').text($row.data('project') || '—');
    $('#banka-tms-detail-duedate').text(formatDisplayDate($row.data('duedate'), false) || '—');
    $('#banka-tms-detail-next-action-date').text(formatDisplayDate($row.data('next-action-date'), false) || '—');
    $('#banka-tms-detail-description').text($row.data('description') || '—');
    const $activity=$('#banka-tms-detail-activity').html('<div class="banka-tms-detail-loading">Loading activity...</div>');
    $.post(bankaTmsApp.ajaxUrl,{action:'banka_tms_app_get_task_activity',nonce:bankaTmsApp.nonce,task_id:$row.data('id')}).done(function(res){
      if(!res||!res.success||!res.data||!Array.isArray(res.data.logs)||!res.data.logs.length){$activity.html('<div class="banka-tms-detail-empty">No activity found.</div>');return;}
      const html=res.data.logs.map(function(log){
        return '<div class="banka-tms-activity-item"><span class="banka-tms-activity-avatar">♙</span><div class="banka-tms-activity-body"><div><strong>'+escapeHtml(log.user||'User')+'</strong> updated status to <b>'+escapeHtml(log.action||'')+'</b></div><div class="banka-tms-activity-remark">'+escapeHtml(log.remarks||'')+'</div>'+(log.next_action_date?'<div class="banka-tms-activity-next-date"><strong>Next Action Date:</strong> '+escapeHtml(log.next_action_date)+'</div>':'')+'<small>'+escapeHtml(log.date||'')+'</small></div></div>';
      }).join('');
      $activity.html(html);
    }).fail(function(xhr){let m='Unable to load activity.'; if(xhr&&xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)m=xhr.responseJSON.data.message; $activity.html('<div class="banka-tms-detail-empty">'+escapeHtml(m)+'</div>');});
  }

  function populateTaskProjectFilter(){
    const $filter = $('#banka-tms-filter-project');
    if (!$filter.length) return;
    const current = $filter.val() || '';
    const seen = {};
    const names = [];
    (bankaTmsApp.projects || []).forEach(function(name){
      name = String(name || '').trim();
      const key = name.toLowerCase();
      if (name && !seen[key]) { seen[key] = true; names.push(name); }
    });
    names.sort(function(a,b){ return a.localeCompare(b); });
    const options = ['<option value="">All Projects</option>'];
    names.forEach(function(name){
      const safe = $('<div>').text(name).html();
      options.push('<option value="' + safe + '">' + safe + '</option>');
    });
    $filter.html(options.join('')).val(current);
  }

  function applyTaskFilters(){
    const q = ($('#banka-tms-task-search').val() || '').toLowerCase().trim();
    const status = $('#banka-tms-filter-status').val() || '';
    const priority = $('#banka-tms-filter-priority').val() || '';
    const project = $('#banka-tms-filter-project').val() || '';
    const owner = $('#banka-tms-filter-owner').val() || '';
    const assignee = $('#banka-tms-filter-assignee').val() || '';
    const scopeUser = ($('#banka-tms-task-data-table').attr('data-scope-user') || '').toString();
    const fromDate = $('#banka-tms-filter-from').val() || '';
    const toDate = $('#banka-tms-filter-to').val() || '';
    const overdueOnly = String($('#banka-tms-task-data-table').attr('data-overdue-filter') || '') === '1';
    const today = new Date();
    const todayString = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    let visibleCount = 0;

    $('#banka-tms-task-table-body tr').not('.banka-tms-initial-empty-row, .banka-tms-filter-empty-row').each(function(){
      const $row = $(this);
      const rowText = $row.text().toLowerCase();
      const rowStatus = ($row.data('status') || '').toString();
      const rowPriority = ($row.data('priority') || '').toString();
      const rowProject = ($row.data('project') || '').toString();
      const rowOwner = ($row.data('owner') || '').toString();
      const rowAssignee = ($row.data('assignee') || '').toString();
      const rowDate = ($row.data('duedate') || '').toString();

      let show = true;
      if (q && rowText.indexOf(q) === -1) show = false;
      if (status && rowStatus !== status) show = false;
      if (priority && rowPriority !== priority) show = false;
      if (project && rowProject !== project) show = false;
      if (owner && rowOwner !== owner) show = false;
      if (assignee && rowAssignee !== assignee) show = false;
      if (scopeUser && rowAssignee !== scopeUser && rowOwner !== scopeUser) show = false;
      if (fromDate && rowDate && rowDate < fromDate) show = false;
      if (toDate && rowDate && rowDate > toDate) show = false;
      if (overdueOnly && (!rowDate || rowDate >= todayString || rowStatus === 'Completed')) show = false;

      $row.toggleClass('banka-tms-task-filter-hidden', !show);
      $row.toggleClass('banka-tms-action-log-filter-hidden', !show);
      $row.toggle(show);
      if (show) visibleCount++;
    });

    $('.banka-tms-filter-empty-row').toggle(visibleCount === 0);
    $('.banka-tms-initial-empty-row').toggle(visibleCount === 0 && $('#banka-tms-task-table-body tr').not('.banka-tms-initial-empty-row, .banka-tms-filter-empty-row').length === 0);
    const taskWrap = document.querySelector('.banka-tms-task-page .banka-tms-advanced-table-wrap');
    if (taskWrap) taskWrap.scrollLeft = 0;
  }

  function refreshTaskCategoryDropdown(selectedValue, done) {
    const $select = $('#task-category');
    if (!$select.length) {
      if (typeof done === 'function') done(false);
      return;
    }

    $.ajax({
      url: bankaTmsApp.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      cache: false,
      data: {
        action: 'banka_tms_app_list_categories',
        nonce: bankaTmsApp.nonce,
        _t: Date.now()
      }
    }).done(function(res){
      if (!res || !res.success || !res.data || !Array.isArray(res.data.categories)) {
        if (typeof done === 'function') done(false);
        return;
      }

      $select.empty().append($('<option>').val('').text('Select Category...'));
      res.data.categories.forEach(function(category){
        if (!category || !category.name) return;
        $select.append($('<option>').val(String(category.name)).text(String(category.name)));
      });

      if (selectedValue) $select.val(String(selectedValue));
      if (typeof done === 'function') done(true);
    }).fail(function(){
      if (typeof done === 'function') done(false);
    });
  }

  function applyTaskFiltersFromUrl(){
    if (!$('#banka-tms-task-data-table').length) return;
    const params = new URLSearchParams(window.location.search || '');
    const taskStatus = params.get('task_status') || '';
    const priority = params.get('priority') || '';
    const project = params.get('project') || '';
    const owner = params.get('owner') || '';
    const assignee = params.get('assignee') || '';
    const scopeUser = params.get('scope_user') || '';
    const fromDate = params.get('from') || '';
    const toDate = params.get('to') || '';
    const search = params.get('q') || '';
    const overdue = params.get('overdue') === '1';

    $('#banka-tms-task-search').val(search);
    $('#banka-tms-filter-status').val(taskStatus);
    $('#banka-tms-filter-priority').val(priority);
    $('#banka-tms-filter-project').val(project);
    $('#banka-tms-filter-owner').val(owner);
    $('#banka-tms-filter-assignee').val(assignee);
    $('#banka-tms-task-data-table').attr('data-scope-user', scopeUser);
    $('#banka-tms-filter-from').val(fromDate);
    $('#banka-tms-filter-to').val(toDate);
    $('#banka-tms-task-data-table').attr('data-overdue-filter', overdue ? '1' : '0');

    $('#banka-tms-filter-status, #banka-tms-filter-priority, #banka-tms-filter-project, #banka-tms-filter-owner, #banka-tms-filter-assignee').trigger('change');
  }

  // Always open task tables from the first column. Browsers may restore an old horizontal scroll position.
  const bankaTmsTaskTableWrap = document.querySelector('.banka-tms-task-page .banka-tms-advanced-table-wrap');
  if (bankaTmsTaskTableWrap) {
    bankaTmsTaskTableWrap.scrollLeft = 0;
    window.requestAnimationFrame(function(){ bankaTmsTaskTableWrap.scrollLeft = 0; });
  }

  if ($('#banka-tms-task-modal').length) {
    $('#banka-tms-open-task-modal').on('click', function(){
      resetTaskForm();
      refreshTaskCategoryDropdown('', function(){
        openModal('#banka-tms-task-modal');
      });
    });
    $(document).on('click', '.banka-tms-edit-task', function(){
      const $row = $(this).closest('tr');
      fillTaskFormFromRow($row);
      refreshTaskCategoryDropdown($row.data('category') || '', function(){
        fillTaskFormFromRow($row);
        openModal('#banka-tms-task-modal');
      });
    });
    $(document).on('click', '.banka-tms-quick-update-task', function(){
      const $row = $(this).closest('tr');
      $('#quick-update-task-id').val($row.data('id'));
      $('#quick-update-status').val('In Progress');
      $('#quick-update-remarks').val($row.data('remarks') || '');
      $('#quick-update-assignee').val('');
      $('#quick-update-next-action-date').val($row.data('next-action-date') || '');
      $('#banka-tms-next-action-wrap').toggle(($('#quick-update-status').val() || '') !== 'Completed');
      $('#banka-tms-quick-update-status').text('');
      openModal('#banka-tms-task-update-modal');
    });
    $(document).on('click', '.banka-tms-view-task', function(){
      const $row = $(this).closest('tr');
      fillTaskDetailFromRow($row);
      openModal('#banka-tms-task-view-modal');
    });
    $('#banka-tms-quick-update-form').on('submit', function(e){
      e.preventDefault();
      const taskId = $('#quick-update-task-id').val();
      const statusBox = $('#banka-tms-quick-update-status');
      const selectedStatus = $('#quick-update-status').val();
      const nextActionDate = $('#quick-update-next-action-date').val();
      if (selectedStatus !== 'Completed' && nextActionDate) {
        const selected = new Date(nextActionDate + 'T00:00:00');
        const today = new Date();
        today.setHours(0,0,0,0);
        if (selected <= today) {
          statusBox.text('Next Action Date must be later than today. Please select a future date.');
          $('#quick-update-next-action-date').trigger('focus');
          return;
        }
      }
      statusBox.text('Updating...');
      $.post(bankaTmsApp.ajaxUrl, {
        action: 'banka_tms_app_quick_update_task',
        nonce: bankaTmsApp.nonce,
        id: taskId,
        status: $('#quick-update-status').val(),
        remarks: $('#quick-update-remarks').val(),
        assignee_id: $('#quick-update-assignee').val(),
        next_action_date: $('#quick-update-next-action-date').val()
      }).done(function(res){
        if (res && res.success) {
          const $row = $('#banka-tms-task-table-body tr[data-id="' + taskId + '"]');
          const status = res.data.status;
          $row.attr('data-status', status).data('status', status);
          $row.attr('data-remarks', res.data.remarks || '').data('remarks', res.data.remarks || '');
          $row.attr('data-assigneeid', res.data.assignee_id || 0).data('assigneeid', res.data.assignee_id || 0);
          $row.attr('data-assignee', res.data.assignee_name || '—').data('assignee', res.data.assignee_name || '—');
          $row.children('td').eq(3).text(res.data.assignee_name || '—');
          const pillClass = status === 'Completed' ? 'completed' : (status === 'In Progress' ? 'progress' : 'pending');
          $row.children('td').eq(6).html('<span class="banka-tms-status-pill ' + pillClass + '">' + res.data.status_label + '</span>');
          $row.attr('data-last-update-date', res.data.update_date || '').data('last-update-date', res.data.update_date || '');
          $row.attr('data-last-update-remarks', res.data.remarks || '').data('last-update-remarks', res.data.remarks || '');
          $row.children('td').eq(7).text(res.data.update_date || '');
          $row.children('td').eq(8).text(res.data.remarks || '');
          $row.attr('data-next-action-date', res.data.next_action_date || '').data('next-action-date', res.data.next_action_date || '');
          $row.children('td').eq(9).text(res.data.next_action_date_display || '');
          closeModals();
          applyTaskFilters();
        } else {
          statusBox.text(res && res.data && res.data.message ? res.data.message : 'Unable to update task');
        }
      }).fail(function(xhr){
        let message = 'Unable to update task';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          message = xhr.responseJSON.data.message;
        } else if (xhr && xhr.responseText) {
          try {
            const parsed = JSON.parse(xhr.responseText);
            if (parsed && parsed.data && parsed.data.message) message = parsed.data.message;
          } catch (ignore) {
            const clean = String(xhr.responseText).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            if (clean && clean !== '-1' && clean !== '0') message = clean.substring(0, 300);
          }
        }
        if (xhr && xhr.status) message += ' (HTTP ' + xhr.status + ')';
        statusBox.text(message);
      });
    });

    function getSelectedTaskIds(){
      return $('.banka-tms-row-check:checked').map(function(){ return String($(this).closest('tr').data('id') || ''); }).get().filter(Boolean);
    }
    function updateBulkActionBar(){
      const selected = getSelectedTaskIds();
      const count = selected.length;
      $('#banka-tms-selected-count').text(count + ' task(s) selected');
      $('#banka-tms-bulk-action-bar').toggle(count > 0);
      const visibleChecks = $('.banka-tms-row-check:visible');
      const checkedVisible = visibleChecks.filter(':checked');
      $('#banka-tms-check-all').prop('checked', visibleChecks.length > 0 && checkedVisible.length === visibleChecks.length).prop('indeterminate', checkedVisible.length > 0 && checkedVisible.length < visibleChecks.length);
    }
    function clearTaskSelection(){
      $('.banka-tms-row-check, #banka-tms-check-all').prop('checked', false);
      $('#banka-tms-check-all').prop('indeterminate', false);
      updateBulkActionBar();
    }
    function refreshTaskSerials(){
      $('#banka-tms-task-table-body tr').not('.banka-tms-no-result-row').each(function(index){ $(this).children('td').eq(1).text(index + 1); });
    }
    function performBulkAction(action, assigneeId){
      const ids = getSelectedTaskIds();
      if (!ids.length) return;
      const statusBox = $('#banka-tms-bulk-status');
      if (action === 'delete' && !window.confirm('Delete ' + ids.length + ' selected task(s)?')) return;
      if (action === 'complete' && !window.confirm('Mark ' + ids.length + ' selected task(s) as completed?')) return;
      statusBox.text('Processing...');
      $.post(bankaTmsApp.ajaxUrl, {
        action: 'banka_tms_app_bulk_tasks',
        nonce: bankaTmsApp.nonce,
        bulk_action: action,
        ids: ids,
        assignee_id: assigneeId || ''
      }).done(function(res){
        if (!(res && res.success)) {
          statusBox.text(res && res.data && res.data.message ? res.data.message : 'Unable to complete bulk action');
          return;
        }
        const affected = (res.data && res.data.ids) || ids;
        affected.forEach(function(id){
          const $row = $('#banka-tms-task-table-body tr[data-id="' + id + '"]');
          if (!$row.length) return;
          if (action === 'delete') {
            $row.remove();
          } else if (action === 'complete') {
            $row.attr('data-status','Completed').data('status','Completed');
            $row.children('td').eq(6).html('<span class="banka-tms-status-pill completed">Completed</span>');
            $row.children('td').eq(7).text((res.data && res.data.update_date) || '');
            $row.children('td').eq(8).text('Task completed through bulk action.');
            if ($('.banka-tms-task-page').data('task-view') === 'pending') $row.remove();
          } else if (action === 'reassign') {
            const name = (res.data && res.data.assignee_name) || '';
            $row.attr('data-assignee',name).data('assignee',name);
            $row.attr('data-assigneeid',assigneeId).data('assigneeid',assigneeId);
            $row.children('td').eq(3).text(name || '—');
          }
        });
        closeModals();
        clearTaskSelection();
        statusBox.text((res.data && res.data.message) || 'Bulk action completed successfully.');
        window.setTimeout(function(){ window.location.reload(); }, 350);
      }).fail(function(xhr){
        let message = 'Unable to complete bulk action';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          message = xhr.responseJSON.data.message;
        } else if (xhr && xhr.responseText) {
          try {
            const parsed = JSON.parse(xhr.responseText);
            if (parsed && parsed.data && parsed.data.message) message = parsed.data.message;
          } catch (e) {
            const clean = String(xhr.responseText).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            if (clean && clean !== '-1' && clean !== '0') message = clean.substring(0, 300);
          }
        }
        if (xhr && xhr.status) message += ' (HTTP ' + xhr.status + ')';
        statusBox.text(message);
      });
    }

    $('#banka-tms-check-all').on('change', function(){
      $('.banka-tms-row-check:visible').prop('checked', this.checked);
      updateBulkActionBar();
    });
    $(document).on('change', '.banka-tms-row-check', updateBulkActionBar);
    $('#banka-tms-bulk-delete').on('click', function(){ performBulkAction('delete'); });
    $('#banka-tms-bulk-complete').on('click', function(){ performBulkAction('complete'); });
    $('#banka-tms-bulk-reassign').on('click', function(){
      $('#banka-tms-bulk-assignee').val('').trigger('change');
      $('#banka-tms-bulk-status').text('');
      openModal('#banka-tms-bulk-reassign-modal');
    });
    $('#banka-tms-bulk-reassign-form').on('submit', function(e){
      e.preventDefault();
      const assigneeId = $('#banka-tms-bulk-assignee').val();
      if (!assigneeId) { $('#banka-tms-bulk-status').text('Please select an assignee'); return; }
      performBulkAction('reassign', assigneeId);
    });
    populateTaskProjectFilter();
    applyTaskFiltersFromUrl();
    $('#banka-tms-toggle-task-filters').on('click', function(){
      const panel = $('#banka-tms-advanced-task-filters');
      const isOpen = panel.is(':visible');
      panel.stop(true, true).slideToggle(160);
      $(this).attr('aria-expanded', isOpen ? 'false' : 'true').toggleClass('active', !isOpen);
    });
    $('#banka-tms-task-search, #banka-tms-filter-status, #banka-tms-filter-priority, #banka-tms-filter-project, #banka-tms-filter-owner, #banka-tms-filter-assignee, #banka-tms-filter-from, #banka-tms-filter-to').on('input change', function(){ clearTaskSelection(); applyTaskFilters(); });
    $('#banka-tms-clear-task-filters').on('click', function(){
      $('#banka-tms-task-search').val('');
      $('#banka-tms-filter-status, #banka-tms-filter-priority, #banka-tms-filter-project, #banka-tms-filter-owner, #banka-tms-filter-assignee').val('').trigger('change');
      $('#banka-tms-filter-from, #banka-tms-filter-to').val('');
      $('#banka-tms-task-data-table').attr('data-overdue-filter', '0');
      const params = new URLSearchParams(window.location.search || '');
      const pageStatus = params.get('status');
      const cleanUrl = window.location.pathname + (pageStatus ? '?status=' + encodeURIComponent(pageStatus) : '');
      window.history.replaceState({}, document.title, cleanUrl);
      clearTaskSelection();
      applyTaskFilters();
    });
    updateBulkActionBar();
    applyTaskFilters();

    $('#banka-tms-export-excel').on('click', function(){
      let csv = [];
      $('#banka-tms-task-data-table tr:visible').each(function(){
        const row = [];
        $(this).find('th,td').each(function(index){
          if (index === 0 || index === 11) return;
          row.push('"' + ($(this).text().trim().replace(/"/g, '""')) + '"');
        });
        if (row.length) csv.push(row.join(','));
      });
      const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'all-tasks.csv';
      link.click();
      URL.revokeObjectURL(link.href);
    });

    $('#banka-tms-export-pdf').on('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      const $menu = $('#banka-tms-pdf-menu');
      const open = $menu.is(':visible');
      $menu.toggle(!open);
      $(this).attr('aria-expanded', open ? 'false' : 'true');
    });
    $(document).on('click', function(e){
      if (!$(e.target).closest('.banka-tms-pdf-menu-wrap').length) {
        $('#banka-tms-pdf-menu').hide();
        $('#banka-tms-export-pdf').attr('aria-expanded','false');
      }
    });
    $('#banka-tms-pdf-menu [data-pdf-group]').on('click', function(){
      const params = new URLSearchParams();
      params.set('action','banka_tms_task_pdf');
      params.set('group',$(this).data('pdf-group'));
      params.set('view',$('.banka-tms-task-page').data('task-view') || 'all');
      params.set('search',$('#banka-tms-task-search').val() || '');
      params.set('status_filter',$('#banka-tms-filter-status').val() || '');
      params.set('priority',$('#banka-tms-filter-priority').val() || '');
      params.set('project',$('#banka-tms-filter-project').val() || '');
      params.set('owner',$('#banka-tms-filter-owner').val() || '');
      params.set('assignee',$('#banka-tms-filter-assignee').val() || '');
      params.set('from',$('#banka-tms-filter-from').val() || '');
      params.set('to',$('#banka-tms-filter-to').val() || '');
      $('#banka-tms-pdf-menu').hide();
      $('#banka-tms-export-pdf').attr('aria-expanded','false');
      window.location.href = (bankaTmsApp.taskPdfUrl || '') + '?' + params.toString();
    });

    $('#banka-tms-task-form').on('submit', function(e){
      e.preventDefault();
      const statusBox = $('#banka-tms-task-status');
      const taskId = $('#task-id').val();
      statusBox.text('Saving...');
      const action = taskId && taskId !== '0' ? 'banka_tms_app_update_task' : 'banka_tms_app_save_task';
      $.post(bankaTmsApp.ajaxUrl, {
        action: action,
        nonce: bankaTmsApp.nonce,
        id: taskId,
        title: $('#task-title').val(),
        description: $('#task-description').val(),
        status: $('#task-status').val(),
        assignee_id: $('#task-assignee-id').val(),
        owner_id: $('#task-owner-id').val(),
        category: $('#task-category').val(),
        priority: $('#task-priority').val(),
        due_date: $('#task-due-date').val(),
        project: $('#task-project').val()
      }).done(function(res){
        if (res.success) {
          statusBox.text('Task saved successfully.');
          window.location.reload();
        } else {
          statusBox.text(res.data && res.data.message ? res.data.message : 'Unable to save task');
        }
      }).fail(function(xhr){
        let message = 'Unable to save task';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          message = xhr.responseJSON.data.message;
        } else if (xhr && xhr.responseText) {
          try {
            const parsed = JSON.parse(xhr.responseText);
            if (parsed && parsed.data && parsed.data.message) message = parsed.data.message;
          } catch (ignore) {
            const clean = String(xhr.responseText).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            if (clean && clean !== '-1' && clean !== '0') message = clean.substring(0, 300);
          }
        }
        if (xhr && xhr.status) message += ' (HTTP ' + xhr.status + ')';
        statusBox.text(message);
      });
    });
  }

  if ($('#banka-tms-open-user-modal').length) {
    $('#banka-tms-open-user-modal').on('click', function(){
      $('#banka-tms-user-form')[0].reset();
      $('#user-id').val('0');
      $('#user-login').prop('disabled', false);
      $('#user-password').prop('required', true);
      $('#banka-tms-user-password-help').hide();
      $('#banka-tms-user-modal-title').text('Pre-register User');
      $('#banka-tms-user-submit-btn').text('Create User');
      $('#banka-tms-user-status').text('');
      openModal('#banka-tms-user-modal');
    });

    $(document).off('click.bankaUserEdit', '.banka-tms-user-edit').on('click.bankaUserEdit', '.banka-tms-user-edit', function(e){
      if ($(this).is('a[href]')) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      const $row = $(this).closest('tr');
      const userId = parseInt($row.attr('data-user-id'), 10) || 0;
      if (!userId) {
        window.alert('Invalid user. Please refresh the page.');
        return;
      }
      $('#user-id').val(userId);
      $('#user-name').val($row.attr('data-name') || '');
      $('#user-login').val($row.attr('data-login') || '').prop('disabled', true);
      $('#user-email').val($row.attr('data-email') || '');
      $('#user-mobile').val($row.attr('data-mobile') || '');
      $('#user-designation').val($row.attr('data-designation') || '');
      $('#user-category').val($row.attr('data-category') || '');
      $('#user-role').val(($row.attr('data-role') || 'subscriber').toString()).trigger('change');
      $('#user-password').val('').prop('required', false);
      $('#banka-tms-user-password-help').show();
      $('#banka-tms-user-modal-title').text('Edit User');
      $('#banka-tms-user-submit-btn').text('Save Changes');
      $('#banka-tms-user-status').text('');
      openModal('#banka-tms-user-modal');
    });

    function showUserPageMessage(message, isError) {
      const $box = $('#banka-tms-user-page-message');
      if (!$box.length) return;
      $box.text(message || '')
        .toggleClass('is-error', !!isError)
        .toggleClass('is-success', !isError && !!message)
        .toggle(!!message);
    }

    $('#banka-tms-user-data-table').on('click', '.banka-tms-status-toggle', function(e){
      e.preventDefault();
      e.stopPropagation();

      const $button = $(this);
      if ($button.hasClass('is-saving')) return;

      const userId = parseInt($button.attr('data-user-id'), 10) || 0;
      const currentlyActive = String($button.attr('data-active')) === '1';
      const nextActive = !currentlyActive;

      if (!userId) {
        showUserPageMessage('Invalid user. Please refresh the page.', true);
        return;
      }

      showUserPageMessage('Saving user status...', false);
      $button.addClass('is-saving').attr('aria-busy', 'true');

      $.ajax({
        url: bankaTmsApp.ajaxUrl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'banka_tms_app_toggle_user_status',
          nonce: bankaTmsApp.nonce,
          user_id: userId,
          active: nextActive ? '1' : '0'
        }
      }).done(function(res){
        if (!res || !res.success) {
          showUserPageMessage(res && res.data && res.data.message ? res.data.message : 'Unable to change user status.', true);
          return;
        }

        const active = res.data && (res.data.active === true || res.data.active === 1 || res.data.active === '1');
        $button.attr('data-active', active ? '1' : '0')
          .toggleClass('is-active', active)
          .toggleClass('is-inactive', !active)
          .attr('title', active ? 'Deactivate user' : 'Activate user')
          .attr('aria-label', active ? 'Deactivate user' : 'Activate user');

        const circleX = active ? '15.8' : '8.2';
        $button.html('<span class="banka-tms-icon banka-tms-toggle-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="7" width="18" height="10" rx="5"></rect><circle cx="' + circleX + '" cy="12" r="3.2" fill="currentColor" stroke="none"></circle></svg></span>');
        $button.closest('tr').attr('data-active', active ? '1' : '0');
        showUserPageMessage(res.data.message || (active ? 'User activated successfully.' : 'User deactivated successfully.'), false);

        if (res.data.logout_url) {
          window.setTimeout(function(){ window.location.href = res.data.logout_url; }, 900);
        }
      }).fail(function(xhr){
        let message = 'Unable to change user status.';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          message = xhr.responseJSON.data.message;
        }
        showUserPageMessage(message, true);
      }).always(function(){
        $button.removeClass('is-saving').removeAttr('aria-busy');
      });
    });

    $(document).off('click.bankaUserDelete', '.banka-tms-user-delete').on('click.bankaUserDelete', '.banka-tms-user-delete', function(e){
      if ($(this).closest('form.banka-tms-inline-delete-form').length) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      const $button = $(this);
      const $row = $button.closest('tr');
      const userId = parseInt($row.attr('data-user-id'), 10) || 0;
      const userName = $row.attr('data-name') || 'this user';
      if (!userId) {
        window.alert('Invalid user. Please refresh the page.');
        return;
      }
      if (!window.confirm('Delete ' + userName + '?')) return;
      $button.prop('disabled', true).attr('aria-busy', 'true');
      $.ajax({
        url: bankaTmsApp.ajaxUrl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'banka_tms_app_delete_user',
          nonce: bankaTmsApp.nonce,
          user_id: userId
        }
      }).done(function(res){
        if (res && res.success) {
          $row.remove();
          $('#banka-tms-user-data-table tbody tr').not('.banka-tms-user-no-result-row').each(function(index){
            $(this).children('td').first().text(index + 1);
          });
          showUserPageMessage((res.data && res.data.message) || 'User deleted successfully.', false);
        } else {
          window.alert(res && res.data && res.data.message ? res.data.message : 'Unable to delete user');
        }
      }).fail(function(xhr){
        let message = 'Unable to delete user';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) message = xhr.responseJSON.data.message;
        window.alert(message);
      }).always(function(){
        $button.prop('disabled', false).removeAttr('aria-busy');
      });
    });

    $('#banka-tms-user-search').on('input', function(){
      const q = ($(this).val() || '').toLowerCase().trim();
      let visible = 0;
      $('#banka-tms-user-data-table tbody tr').not('.banka-tms-user-no-result-row').each(function(){
        const show = !q || $(this).text().toLowerCase().indexOf(q) !== -1;
        $(this).toggle(show);
        if (show) visible++;
      });
      $('.banka-tms-user-no-result-row').toggle(visible === 0);
    });
  }

  function escapeUserHtml(value) {
    return $('<div>').text(value == null ? '' : String(value)).html();
  }

  function appendCreatedUser(user) {
    if (!user || !user.id) return;
    const $tbody = $('#banka-tms-user-data-table tbody');
    const serial = $tbody.find('tr').not('.banka-tms-user-no-result-row').length + 1;
    const row = '<tr data-user-id="' + escapeUserHtml(user.id) + '"' +
      ' data-name="' + escapeUserHtml(user.name) + '"' +
      ' data-login="' + escapeUserHtml(user.login) + '"' +
      ' data-email="' + escapeUserHtml(user.email) + '"' +
      ' data-mobile="' + escapeUserHtml(user.mobile) + '"' +
      ' data-designation="' + escapeUserHtml(user.designation) + '"' +
      ' data-category="' + escapeUserHtml(user.category || '') + '"' +
      ' data-role="' + escapeUserHtml(user.role_key) + '">' +
      '<td>' + serial + '</td>' +
      '<td>' + escapeUserHtml(user.name) + '</td>' +
      '<td>' + escapeUserHtml(user.email) + '</td>' +
      '<td>' + escapeUserHtml(user.mobile) + '</td>' +
      '<td>' + escapeUserHtml(user.designation) + '</td>' +
      '<td>' + escapeUserHtml(user.role) + '</td>' +
      '<td><button type="button" class="banka-tms-status-toggle is-active" data-user-id="' + escapeUserHtml(user.id) + '" data-active="1" title="Deactivate user" aria-label="Deactivate user"><span class="banka-tms-icon banka-tms-toggle-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="7" width="18" height="10" rx="5"></rect><circle cx="15.8" cy="12" r="3.2" fill="currentColor" stroke="none"></circle></svg></span></button></td>' +
      '<td><div class="banka-tms-action-buttons banka-tms-user-action-buttons"><button type="button" class="banka-tms-icon-btn banka-tms-user-edit" title="Edit"><span class="banka-tms-icon banka-tms-user-table-icon banca-tms-green-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m4 20 4.1-1 9.1-9.1-3.1-3.1L5 15.9 4 20Z"></path><path d="m12.9 5 3.1 3.1"></path></svg></span></button><button type="button" class="banka-tms-icon-btn banka-tms-user-delete" title="Delete"><span class="banka-tms-icon banka-tms-user-table-icon banka-tms-red-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4.5 7.5h15"></path><path d="M9 4.8h6l.6 2.7H8.4L9 4.8Z"></path><path d="M8 7.5v10.2A1.3 1.3 0 0 0 9.3 19h5.4a1.3 1.3 0 0 0 1.3-1.3V7.5"></path><path d="M10.2 10.2v5.4M13.8 10.2v5.4"></path></svg></span></button></div></td>' +
      '</tr>';
    $('.banka-tms-user-no-result-row').before(row);
  }

  if ($('#banka-tms-user-form').length) {
    $('#banka-tms-user-form').on('submit', function(e){
      if ($(this).attr('data-server-submit') === '1') return true;
      e.preventDefault();
      const statusBox = $('#banka-tms-user-status');
      const userId = $('#user-id').val();
      const isEdit = userId && userId !== '0';
      statusBox.text(isEdit ? 'Saving changes...' : 'Creating user...');
      $.post(bankaTmsApp.ajaxUrl, {
        action: isEdit ? 'banka_tms_app_update_user' : 'banka_tms_app_create_user',
        nonce: bankaTmsApp.nonce,
        user_id: userId,
        name: $('#user-name').val(),
        login: $('#user-login').val(),
        email: $('#user-email').val(),
        password: $('#user-password').val(),
        role: $('#user-role').val(),
        mobile: $('#user-mobile').val(),
        designation: $('#user-designation').val(),
        category: $('#user-category').val()
      }).done(function(res){
        if (res.success) {
          statusBox.text(isEdit ? 'User updated successfully.' : 'User created successfully.');
          if (isEdit) {
            window.location.href = window.location.pathname + '?tms_refresh=' + Date.now();
          } else {
            appendCreatedUser(res.data && res.data.user ? res.data.user : null);
            $('#banka-tms-user-form')[0].reset();
            $('#user-id').val('0');
            setTimeout(function(){ closeModals(); }, 450);
          }
        } else {
          statusBox.text(res.data && res.data.message ? res.data.message : 'Unable to create user');
        }
      }).fail(function(){
        statusBox.text('Unable to create user');
      });
    });
  }



  if ($('#banka-tms-open-category-modal').length) {
    function showCategoryMessage(message, isError) {
      const $box=$('#banka-tms-category-status');
      if (!message) { $box.hide().removeClass('is-error is-success').text(''); return; }
      $box.text(message).show().toggleClass('is-error',!!isError).toggleClass('is-success',!isError);
    }

    $(document).on('click','#banka-tms-open-category-modal',function(){
      $('#category-id').val('0'); $('#category-name').val(''); $('#banka-tms-category-form-status').text('');
      $('#banka-tms-category-modal-title').text('Add New Category'); $('#banka-tms-category-submit-btn').text('Add Category');
      openModal('#banka-tms-category-modal');
    });

    $(document).on('click','.banka-tms-category-edit',function(){
      const $row=$(this).closest('tr');
      $('#category-id').val($row.data('category-id')); $('#category-name').val($row.data('name')||'');
      $('#banka-tms-category-form-status').text(''); $('#banka-tms-category-modal-title').text('Edit Category'); $('#banka-tms-category-submit-btn').text('Save Changes');
      openModal('#banka-tms-category-modal');
    });

    $(document).on('click','.banka-tms-category-delete',function(){
      const $row=$(this).closest('tr');
      if (!window.confirm('Delete '+($row.data('name')||'this category')+'?')) return;
      $.post(bankaTmsApp.ajaxUrl,{action:'banka_tms_app_delete_category',nonce:bankaTmsApp.nonce,category_id:$row.data('category-id')}).done(function(res){
        if (res.success) { const deletedName=$row.attr('data-name')||''; $row.remove(); renumberCategoryRows(); $('#user-category,#task-category').find('option').filter(function(){return $(this).val()===deletedName;}).remove(); showCategoryMessage(res.data&&res.data.message?res.data.message:'Category deleted successfully',false); } else showCategoryMessage(res.data&&res.data.message?res.data.message:'Unable to delete category',true);
      }).fail(function(){showCategoryMessage('Unable to delete category',true);});
    });

    $(document).on('input','#banka-tms-category-search',function(){
      const q=($(this).val()||'').toLowerCase().trim(); let visible=0;
      $('#banka-tms-category-table tbody tr').not('.banka-tms-category-no-result-row').each(function(){
        const show=!q||$(this).text().toLowerCase().indexOf(q)!==-1; $(this).toggle(show); if(show) visible++;
      });
      $('.banka-tms-category-no-result-row').toggle(visible===0);
    });

    function escapeCategoryHtml(value){ return $('<div>').text(value == null ? '' : String(value)).html(); }
    function renumberCategoryRows(){
      $('#banka-tms-category-table tbody tr').not('.banka-tms-category-no-result-row').each(function(index){ $(this).find('td').eq(0).text(index+1); });
    }
    function categoryActionHtml(){
      return '<div class="banka-tms-action-buttons banka-tms-user-action-buttons">' +
        '<button type="button" class="banka-tms-icon-btn banka-tms-category-edit" title="Edit"><span class="banka-tms-icon banka-tms-user-table-icon banka-tms-green-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m4 20 4.1-1 9.1-9.1-3.1-3.1L5 15.9 4 20Z"></path><path d="m12.9 5 3.1 3.1"></path></svg></span></button>' +
        '<button type="button" class="banka-tms-icon-btn banka-tms-category-delete" title="Delete"><span class="banka-tms-icon banka-tms-user-table-icon banka-tms-red-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4.5 7.5h15"></path><path d="M9 4.8h6l.6 2.7H8.4L9 4.8Z"></path><path d="M8 7.5v10.2A1.3 1.3 0 0 0 9.3 19h5.4a1.3 1.3 0 0 0 1.3-1.3V7.5"></path><path d="M10.2 10.2v5.4M13.8 10.2v5.4"></path></svg></span></button>' +
        '</div>';
    }
    function renderCategoryRows(categories){
      const $tbody=$('#banka-tms-category-table tbody');
      if(!$tbody.length) return;
      $tbody.find('tr').not('.banka-tms-category-no-result-row').remove();
      (categories||[]).forEach(function(category,index){
        const row='<tr data-category-id="'+escapeCategoryHtml(category.id)+'" data-name="'+escapeCategoryHtml(category.name)+'"><td>'+(index+1)+'</td><td>'+escapeCategoryHtml(category.name)+'</td><td>'+categoryActionHtml()+'</td></tr>';
        $('.banka-tms-category-no-result-row').before(row);
      });
      $('.banka-tms-category-no-result-row').toggle(!(categories&&categories.length));
    }
    function refreshCategoriesFromServer(done){
      $.ajax({
        url:bankaTmsApp.ajaxUrl,
        type:'POST',
        dataType:'json',
        cache:false,
        data:{action:'banka_tms_app_list_categories',nonce:bankaTmsApp.nonce,_t:Date.now()}
      }).done(function(res){
        if(res&&res.success&&res.data&&Array.isArray(res.data.categories)){
          renderCategoryRows(res.data.categories);
          if(typeof done==='function') done(true,res.data.categories);
        }else if(typeof done==='function') done(false,[]);
      }).fail(function(){ if(typeof done==='function') done(false,[]); });
    }

    function addCategoryToDropdowns(category){
      if(!category || !category.name) return;
      $('#user-category,#task-category').each(function(){
        const $select=$(this);
        const exists=$select.find('option').filter(function(){ return $(this).val()===String(category.name); }).length;
        if(!exists) $select.append($('<option>').val(category.name).text(category.name));
      });
    }
    function updateCategoryDropdowns(oldName,newName){
      $('#user-category,#task-category').each(function(){
        $(this).find('option').filter(function(){ return $(this).val()===String(oldName); }).val(newName).text(newName);
      });
    }

    // Category form uses a normal server-side POST for reliable saving on cached hosting.

    $(document).on('click','#banka-tms-category-excel',function(){
      const rows=[['S.No.','Category Name']];
      $('#banka-tms-category-table tbody tr:visible').not('.banka-tms-category-no-result-row').each(function(){rows.push([$(this).find('td').eq(0).text().trim(),$(this).find('td').eq(1).text().trim()]);});
      const csv=rows.map(r=>r.map(v=>'\"'+String(v).replace(/\"/g,'\"\"')+'\"').join(',')).join('\n');
      const blob=new Blob([csv],{type:'text/csv;charset=utf-8;'}); const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='categories.csv'; a.click(); URL.revokeObjectURL(a.href);
    });
    $(document).on('click','#banka-tms-category-pdf',function(){window.print();});
    refreshCategoriesFromServer();
  }


  if ($('#banka-tms-open-task-master-modal').length) {
    function showTaskMasterMessage(message, isError) {
      const $box = $('#banka-tms-task-master-page-message');
      if (!$box.length) return;
      $box.text(message || '')
        .toggleClass('is-error', !!isError)
        .toggleClass('is-success', !isError && !!message)
        .toggle(!!message);
    }

    function renumberTaskMasterRows() {
      $('#banka-tms-task-master-table tbody tr').not('.banka-tms-task-master-no-result-row').each(function(index){
        $(this).children('td').first().text(index + 1);
      });
    }

    function syncTaskMasterOptions(oldName, newName, remove) {
      $('#task-title, select[data-task-master-dropdown="1"]').each(function(){
        const $select = $(this);
        if (remove) {
          $select.find('option').filter(function(){ return $(this).val() === oldName; }).remove();
          return;
        }
        const $existing = $select.find('option').filter(function(){ return $(this).val() === oldName; }).first();
        if ($existing.length) {
          $existing.val(newName).text(newName);
        } else if (!$select.find('option').filter(function(){ return $(this).val() === newName; }).length) {
          $select.append($('<option>').val(newName).text(newName));
        }
      });
    }

    $('#banka-tms-open-task-master-modal').on('click', function(){
      $('#task-master-id').val('0');
      $('#task-master-name').val('');
      $('#banka-tms-task-master-form-status').text('');
      $('#banka-tms-task-master-modal-title').text('Add New Task');
      $('#banka-tms-task-master-submit-btn').text('Add Task');
      openModal('#banka-tms-task-master-modal');
    });

    $('#banka-tms-task-master-table').on('click', '.banka-tms-task-master-edit', function(){
      const $row = $(this).closest('tr');
      $('#task-master-id').val($row.attr('data-task-master-id'));
      $('#task-master-name').val($row.attr('data-name') || '');
      $('#banka-tms-task-master-form-status').text('');
      $('#banka-tms-task-master-modal-title').text('Edit Task');
      $('#banka-tms-task-master-submit-btn').text('Save Changes');
      openModal('#banka-tms-task-master-modal');
    });

    $('#banka-tms-task-master-table').on('click', '.banka-tms-task-master-delete', function(){
      const $row = $(this).closest('tr');
      const id = $row.attr('data-task-master-id');
      const name = $row.attr('data-name') || 'this task';
      if (!window.confirm('Delete ' + name + '?')) return;
      $.post(bankaTmsApp.ajaxUrl, {
        action: 'banka_tms_app_delete_task_master',
        nonce: bankaTmsApp.nonce,
        task_master_id: id
      }).done(function(res){
        if (res.success) {
          $row.remove();
          renumberTaskMasterRows();
          syncTaskMasterOptions(name, '', true);
          showTaskMasterMessage(res.data && res.data.message ? res.data.message : 'Task deleted successfully', false);
        } else {
          showTaskMasterMessage(res.data && res.data.message ? res.data.message : 'Unable to delete task', true);
        }
      }).fail(function(){ showTaskMasterMessage('Unable to delete task', true); });
    });

    $('#banka-tms-task-master-form').on('submit', function(e){
      e.preventDefault();
      const id = $('#task-master-id').val();
      const isEdit = id && id !== '0';
      const name = ($('#task-master-name').val() || '').trim();
      const $status = $('#banka-tms-task-master-form-status');
      if (!name) { $status.text('Task name is required'); return; }
      const $oldRow = isEdit ? $('#banka-tms-task-master-table tbody tr[data-task-master-id="' + id + '"]') : $();
      const oldName = isEdit ? ($oldRow.attr('data-name') || '') : '';
      $status.text('Saving...');
      $.post(bankaTmsApp.ajaxUrl, {
        action: isEdit ? 'banka_tms_app_update_task_master' : 'banka_tms_app_save_task_master',
        nonce: bankaTmsApp.nonce,
        task_master_id: id,
        name: name
      }).done(function(res){
        if (!res.success) {
          $status.text(res.data && res.data.message ? res.data.message : 'Unable to save task');
          return;
        }
        if (isEdit) {
          $oldRow.attr('data-name', name).children('td').eq(1).text(name);
          syncTaskMasterOptions(oldName, name, false);
        } else {
          const task = res.data && res.data.task ? res.data.task : {id: '', name: name};
          const rowHtml = '<tr data-task-master-id="' + $('<div>').text(task.id).html() + '" data-name="' + $('<div>').text(task.name).html() + '">' +
            '<td></td><td>' + $('<div>').text(task.name).html() + '</td><td><div class="banka-tms-action-buttons banka-tms-user-action-buttons">' +
            '<button type="button" class="banka-tms-icon-btn banka-tms-task-master-edit" title="Edit">✎</button>' +
            '<button type="button" class="banka-tms-icon-btn banka-tms-task-master-delete" title="Delete">🗑</button></div></td></tr>';
          $('.banka-tms-task-master-no-result-row').before(rowHtml);
          syncTaskMasterOptions('', task.name, false);
        }
        renumberTaskMasterRows();
        closeModals();
        showTaskMasterMessage(res.data && res.data.message ? res.data.message : 'Task saved successfully', false);
      }).fail(function(){ $status.text('Unable to save task'); });
    });
  }

  if ($('#banka-tms-open-designation-modal').length) {
    function showDesignationMessage(message, isError) {
      const $box = $('#banka-tms-designation-status');
      if (!$box.length) return;
      if (!message) {
        $box.hide().removeClass('is-error is-success').text('');
        return;
      }
      $box.text(message).show().toggleClass('is-error', !!isError).toggleClass('is-success', !isError);
    }

    $('#banka-tms-open-designation-modal').on('click', function(){
      $('#designation-id').val('0');
      $('#designation-name').val('');
      $('#banka-tms-designation-form-status').text('');
      $('#banka-tms-designation-modal-title').text('Add Designation');
      $('#banka-tms-designation-submit-btn').text('Save Designation');
      openModal('#banka-tms-designation-modal');
    });

    $('#banka-tms-designation-table').on('click', '.banka-tms-designation-edit', function(){
      const $row = $(this).closest('tr');
      $('#designation-id').val($row.data('designation-id'));
      $('#designation-name').val($row.data('name') || '');
      $('#banka-tms-designation-form-status').text('');
      $('#banka-tms-designation-modal-title').text('Edit Designation');
      $('#banka-tms-designation-submit-btn').text('Save Changes');
      openModal('#banka-tms-designation-modal');
    });

    $('#banka-tms-designation-table').on('click', '.banka-tms-designation-delete', function(){
      const $row = $(this).closest('tr');
      const designationId = $row.data('designation-id');
      const name = $row.data('name') || 'this designation';
      if (!window.confirm('Delete ' + name + '?')) return;
      $.post(bankaTmsApp.ajaxUrl, {
        action: 'banka_tms_app_delete_designation',
        nonce: bankaTmsApp.nonce,
        designation_id: designationId
      }).done(function(res){
        if (res.success) {
          window.location.reload();
        } else {
          showDesignationMessage(res.data && res.data.message ? res.data.message : 'Unable to delete designation', true);
        }
      }).fail(function(xhr){
        const msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
          ? xhr.responseJSON.data.message
          : 'Unable to delete designation (HTTP ' + xhr.status + ')';
        showDesignationMessage(msg, true);
      });
    });

    $('#banka-tms-designation-search').on('input', function(){
      const q = ($(this).val() || '').toLowerCase().trim();
      let visible = 0;
      $('#banka-tms-designation-table tbody tr').not('.banka-tms-designation-no-result-row').each(function(){
        const show = !q || $(this).text().toLowerCase().indexOf(q) !== -1;
        $(this).toggle(show);
        if (show) visible++;
      });
      $('.banka-tms-designation-no-result-row').toggle(visible === 0);
    });

    $('#banka-tms-designation-form').on('submit', function(e){
      e.preventDefault();
      const designationId = $('#designation-id').val();
      const isEdit = designationId && designationId !== '0';
      const statusBox = $('#banka-tms-designation-form-status');
      statusBox.text(isEdit ? 'Saving changes...' : 'Saving...');
      $.post(bankaTmsApp.ajaxUrl, {
        action: isEdit ? 'banka_tms_app_update_designation' : 'banka_tms_app_save_designation',
        nonce: bankaTmsApp.nonce,
        designation_id: designationId,
        name: $('#designation-name').val()
      }).done(function(res){
        if (res.success) {
          window.location.reload();
        } else {
          statusBox.text(res.data && res.data.message ? res.data.message : 'Unable to save designation');
        }
      }).fail(function(){
        statusBox.text('Unable to save designation');
      });
    });
  }


  function escapeHtml(value){ return $('<div>').text(value == null ? '' : String(value)).html(); }
  function refreshProjectSerials(){ $('#banka-tms-project-table tbody tr').not('.banka-tms-project-no-result-row').each(function(index){ $(this).children('td').eq(0).text(index + 1); }); }
  function updateProjectDropdownOptions(){
    if (!$('#banka-tms-project-table').length) return;
    const names = [];
    $('#banka-tms-project-table tbody tr').not('.banka-tms-project-no-result-row').each(function(){
      const name = $(this).attr('data-project-name') || $(this).children('td').eq(2).text().trim();
      const address = $(this).attr('data-address') || $(this).children('td').eq(6).text().trim();
      names.push(address ? (name + ' (' + address + ')') : name);
    });
    if ($('#task-project').length) {
      const current = $('#task-project').val() || '';
      const opts = ['<option value="">Select Project...</option>'];
      names.forEach(function(name){ opts.push('<option value="'+escapeHtml(name)+'">'+escapeHtml(name)+'</option>'); });
      $('#task-project').html(opts.join('')).val(current);
    }
    if (window.bankaTmsApp) { bankaTmsApp.projects = names; }
  }
  function refreshProjectFilters(){
    const types = new Set();
    const categories = new Set(['Architect', 'Interior']);
    $('#banka-tms-project-table tbody tr').not('.banka-tms-project-no-result-row').each(function(){
      const t = $(this).attr('data-project-type') || '';
      const c = $(this).attr('data-category') || '';
      if (t) types.add(t);
      if (c && ['architect', 'interior'].indexOf(String(c).toLowerCase()) !== -1) {
        categories.add(String(c).toLowerCase() === 'architect' ? 'Architect' : 'Interior');
      }
    });
    const typeCurrent = $('#banka-tms-project-type-filter').val() || '';
    const catCurrent = $('#banka-tms-project-category-filter').val() || '';
    const typeOpts=['<option value="">Project Type</option>']; Array.from(types).sort().forEach(function(v){ typeOpts.push('<option value="'+escapeHtml(v)+'">'+escapeHtml(v)+'</option>'); });
    const catOpts=['<option value="">Project Category</option>']; Array.from(categories).sort().forEach(function(v){ catOpts.push('<option value="'+escapeHtml(v)+'">'+escapeHtml(v)+'</option>'); });
    $('#banka-tms-project-type-filter').html(typeOpts.join('')).val(typeCurrent);
    $('#banka-tms-project-category-filter').html(catOpts.join('')).val(catCurrent);
  }
  function applyProjectFilters(){
    const q = ($('#banka-tms-project-search').val() || '').toLowerCase().trim();
    const type = ($('#banka-tms-project-type-filter').val() || '').toLowerCase().trim();
    const category = ($('#banka-tms-project-category-filter').val() || '').toLowerCase().trim();
    let visible = 0;
    $('#banka-tms-project-table tbody tr').not('.banka-tms-project-no-result-row').each(function(){
      const $tr = $(this);
      const hay = $tr.text().toLowerCase();
      const typeVal = String($tr.attr('data-project-type') || '').toLowerCase();
      const catVal = String($tr.attr('data-category') || '').toLowerCase();
      const show = (!q || hay.indexOf(q) !== -1) && (!type || typeVal === type) && (!category || catVal === category);
      $tr.toggleClass('banka-tms-project-filter-hidden', !show);
      if (show) {
        $tr.show();
        visible++;
      } else {
        $tr.hide().removeAttr('data-tms-page-hidden');
      }
    });
    $('.banka-tms-project-no-result-row').toggle(visible === 0);
  }
  function projectRowMarkup(project, index, detailsIcon, editIcon, deleteIcon){
    const meta = project.meta || {};
    return '<tr data-project-id="'+escapeHtml(project.id)+'" data-project-code="'+escapeHtml(project.project_code)+'" data-project-name="'+escapeHtml(project.project_name)+'" data-client-name="'+escapeHtml(project.client_name)+'" data-category="'+escapeHtml(project.category)+'" data-project-type="'+escapeHtml(project.project_type)+'" data-address="'+escapeHtml(project.address)+'"'
      + ' data-telegram-group-id="'+escapeHtml(meta.telegram_group_id || '')+'" data-architect-1="'+escapeHtml(meta.architect_1 || '')+'" data-architect-2="'+escapeHtml(meta.architect_2 || '')+'" data-asst-architect="'+escapeHtml(meta.asst_architect || '')+'" data-structural-inhouse="'+escapeHtml(meta.structural_inhouse || '')+'" data-structural-consultant="'+escapeHtml(meta.structural_consultant || '')+'" data-mep-consultant="'+escapeHtml(meta.mep_consultant || '')+'" data-landscape-consultant="'+escapeHtml(meta.landscape_consultant || '')+'" data-views-3d="'+escapeHtml(meta.views_3d || '')+'" data-working="'+escapeHtml(meta.working || '')+'" data-permission-drawing="'+escapeHtml(meta.permission_drawing || '')+'" data-site-engineer="'+escapeHtml(meta.site_engineer || '')+'" data-status-1="'+escapeHtml(meta.status_1 || '')+'" data-status-2="'+escapeHtml(meta.status_2 || '')+'" data-status-3="'+escapeHtml(meta.status_3 || '')+'">'
      + '<td>'+(index||1)+'</td>'
      + '<td>'+escapeHtml(project.project_code)+'</td>'
      + '<td>'+escapeHtml(project.project_name)+'</td>'
      + '<td>'+escapeHtml(project.client_name)+'</td>'
      + '<td>'+escapeHtml(project.category)+'</td>'
      + '<td>'+escapeHtml(project.project_type)+'</td>'
      + '<td>'+escapeHtml(project.address)+'</td>'
      + '<td>'+escapeHtml(meta.telegram_group_id || '')+'</td>'
      + '<td>'+escapeHtml(meta.architect_1 || '')+'</td>'
      + '<td>'+escapeHtml(meta.architect_2 || '')+'</td>'
      + '<td>'+escapeHtml(meta.asst_architect || '')+'</td>'
      + '<td>'+escapeHtml(meta.structural_inhouse || '')+'</td>'
      + '<td>'+escapeHtml(meta.structural_consultant || '')+'</td>'
      + '<td>'+escapeHtml(meta.mep_consultant || '')+'</td>'
      + '<td>'+escapeHtml(meta.landscape_consultant || '')+'</td>'
      + '<td>'+escapeHtml(meta.views_3d || '')+'</td>'
      + '<td>'+escapeHtml(meta.working || '')+'</td>'
      + '<td>'+escapeHtml(meta.permission_drawing || '')+'</td>'
      + '<td>'+escapeHtml(meta.site_engineer || '')+'</td>'
      + '<td>'+escapeHtml(meta.status_1 || '')+'</td>'
      + '<td>'+escapeHtml(meta.status_2 || '')+'</td>'
      + '<td>'+escapeHtml(meta.status_3 || '')+'</td>'
      + '<td><div class="banka-tms-action-buttons banka-tms-project-action-buttons"><button type="button" class="banka-tms-icon-btn banka-tms-project-details" title="Details">'+detailsIcon+'</button><button type="button" class="banka-tms-icon-btn banka-tms-project-edit" title="Edit">'+editIcon+'</button><button type="button" class="banka-tms-icon-btn banka-tms-project-delete" title="Delete">'+deleteIcon+'</button></div></td></tr>';
  }
  function resetProjectForm(){
    $('#project-id').val('0');
    $('#project-code,#project-name,#project-telegram-group-id,#project-address,#project-client-name,#project-type,#project-category,#project-architect-1,#project-architect-2,#project-asst-architect,#project-structural-inhouse,#project-structural-consultant,#project-mep-consultant,#project-landscape-consultant,#project-views-3d,#project-working,#project-permission-drawing,#project-site-engineer,#project-status-1,#project-status-2,#project-status-3').val('');
    $('#project-code').attr('placeholder','Will be auto-generated after save');
    $('#banka-tms-project-status').text('');
    $('#banka-tms-project-modal-title').text('Add New Project');
    $('#banka-tms-project-submit-btn').text('Add New Project');
  }
  function showProjectMessage(message, error){ const box=$('#banka-tms-project-page-message'); box.text(message || '').toggleClass('error', !!error).toggle(!!message); }
  if ($('#banka-tms-project-modal').length) {
    if ($('#banka-tms-project-table').length) {
      updateProjectDropdownOptions();
      refreshProjectFilters();
    }
    $('#banka-tms-open-project-modal').on('click', function(){ resetProjectForm(); openModal('#banka-tms-project-modal'); });
    $('#banka-tms-project-search, #banka-tms-project-type-filter, #banka-tms-project-category-filter').on('input change', applyProjectFilters);
    $('#banka-tms-clear-project-filters').on('click', function(){
      $('#banka-tms-project-search').val('');
      $('#banka-tms-project-type-filter, #banka-tms-project-category-filter').val('').trigger('change');
      applyProjectFilters();
    });

    $(document).on('click', '.banka-tms-project-details', function(){
      const $tr = $(this).closest('tr');
      const projectId = $tr.attr('data-project-id');
      const projectName = $tr.attr('data-project-name') || 'Project';
      const projectCode = $tr.attr('data-project-code') || '';
      const clientName = $tr.attr('data-client-name') || '—';
      const category = $tr.attr('data-category') || '—';
      const projectType = $tr.attr('data-project-type') || '—';
      const address = $tr.attr('data-address') || '—';
      $('#banka-tms-project-details-title').text(projectName);
      $('#banka-tms-project-details-subtitle').text(projectCode ? 'Project ID: ' + projectCode : '');
      $('#banka-tms-project-details-summary').html(
        '<div><small>Client Name</small><strong>'+escapeHtml(clientName)+'</strong></div>'+
        '<div><small>Category</small><strong>'+escapeHtml(category)+'</strong></div>'+
        '<div><small>Project Type</small><strong>'+escapeHtml(projectType)+'</strong></div>'+
        '<div><small>Address</small><strong>'+escapeHtml(address)+'</strong></div>'
      );
      $('#banka-tms-project-log-body').html('<tr><td colspan="7" class="banka-tms-empty">Loading project activity...</td></tr>');
      openModal('#banka-tms-project-details-modal');
      $.post(bankaTmsApp.ajaxUrl, {
        action:'banka_tms_app_get_project_logs',
        nonce:bankaTmsApp.nonce,
        project_id:projectId
      }).done(function(res){
        if (!res || !res.success) {
          $('#banka-tms-project-log-body').html('<tr><td colspan="7" class="banka-tms-empty">'+escapeHtml((res && res.data && res.data.message) || 'Unable to load project activity.')+'</td></tr>');
          return;
        }
        const logs = (res.data && res.data.logs) || [];
        if (!logs.length) {
          $('#banka-tms-project-log-body').html('<tr><td colspan="7" class="banka-tms-empty">No action log found for this project.</td></tr>');
          return;
        }
        const rows = logs.map(function(log, index){
          return '<tr>'+
            '<td>'+(index+1)+'</td>'+
            '<td>'+escapeHtml(log.created_display || '')+'</td>'+
            '<td>'+escapeHtml(log.task_title || '—')+'</td>'+
            '<td>'+escapeHtml(log.status || '—')+'</td>'+
            '<td>'+escapeHtml(log.remarks || '—')+'</td>'+
            '<td>'+escapeHtml(log.owners || '—')+'</td>'+
            '<td>'+escapeHtml(log.assignees || '—')+'</td>'+
          '</tr>';
        });
        $('#banka-tms-project-log-body').html(rows.join(''));
      }).fail(function(){
        $('#banka-tms-project-log-body').html('<tr><td colspan="7" class="banka-tms-empty">Unable to load project activity.</td></tr>');
      });
    });

    $(document).on('click', '.banka-tms-project-edit', function(){
      const $tr = $(this).closest('tr');
      $('#project-id').val($tr.attr('data-project-id'));
      $('#project-code').val($tr.attr('data-project-code'));
      $('#project-name').val($tr.attr('data-project-name'));
      $('#project-telegram-group-id').val($tr.attr('data-telegram-group-id'));
      $('#project-address').val($tr.attr('data-address'));
      $('#project-client-name').val($tr.attr('data-client-name'));
      $('#project-type').val($tr.attr('data-project-type'));
      $('#project-category').val($tr.attr('data-category'));
      $('#project-architect-1').val($tr.attr('data-architect-1'));
      $('#project-architect-2').val($tr.attr('data-architect-2'));
      $('#project-asst-architect').val($tr.attr('data-asst-architect'));
      $('#project-structural-inhouse').val($tr.attr('data-structural-inhouse'));
      $('#project-structural-consultant').val($tr.attr('data-structural-consultant'));
      $('#project-mep-consultant').val($tr.attr('data-mep-consultant'));
      $('#project-landscape-consultant').val($tr.attr('data-landscape-consultant'));
      $('#project-views-3d').val($tr.attr('data-views-3d'));
      $('#project-working').val($tr.attr('data-working'));
      $('#project-permission-drawing').val($tr.attr('data-permission-drawing'));
      $('#project-site-engineer').val($tr.attr('data-site-engineer'));
      $('#project-status-1').val($tr.attr('data-status-1'));
      $('#project-status-2').val($tr.attr('data-status-2'));
      $('#project-status-3').val($tr.attr('data-status-3'));
      $('#banka-tms-project-status').text('');
      $('#banka-tms-project-modal-title').text('Edit Project');
      $('#banka-tms-project-submit-btn').text('Save Changes');
      openModal('#banka-tms-project-modal');
    });
    $(document).on('click', '.banka-tms-project-delete', function(){
      if (!confirm('Delete this project?')) return;
      const $tr = $(this).closest('tr');
      $.post(bankaTmsApp.ajaxUrl, { action:'banka_tms_app_delete_project', nonce:bankaTmsApp.nonce, project_id:$tr.attr('data-project-id') }).done(function(res){
        if (res && res.success) {
          $tr.remove();
          refreshProjectSerials(); refreshProjectFilters(); applyProjectFilters(); updateProjectDropdownOptions();
          showProjectMessage((res.data && res.data.message) || 'Project deleted successfully', false);
        } else {
          showProjectMessage((res.data && res.data.message) || 'Unable to delete project', true);
        }
      }).fail(function(xhr){
        const msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
          ? xhr.responseJSON.data.message
          : 'Unable to delete project (HTTP ' + xhr.status + ')';
        showProjectMessage(msg, true);
      });
    });
    $('#banka-tms-project-form').on('submit', function(e){
      e.preventDefault();
      const projectAddress = $.trim($('#project-address').val());
      if (!projectAddress) {
        $('#banka-tms-project-status').text('Address is required');
        $('#project-address').trigger('focus');
        return;
      }
      const isEdit = $('#project-id').val() && $('#project-id').val() !== '0';
      const status = $('#banka-tms-project-status');
      const detailsIcon = $('.banka-tms-project-details').first().html() || '';
      const editIcon = $('.banka-tms-project-edit').first().html() || '';
      const deleteIcon = $('.banka-tms-project-delete').first().html() || '';
      status.text(isEdit ? 'Saving changes...' : 'Saving project...');
      $.post(bankaTmsApp.ajaxUrl, {
        action:isEdit ? 'banka_tms_app_update_project' : 'banka_tms_app_save_project',
        nonce:bankaTmsApp.nonce,
        project_id:$('#project-id').val(),
        project_code:$('#project-code').val(),
        project_name:$('#project-name').val(),
        telegram_group_id:$('#project-telegram-group-id').val(),
        address:$('#project-address').val(),
        client_name:$('#project-client-name').val(),
        project_type:$('#project-type').val(),
        category:$('#project-category').val(),
        architect_1:$('#project-architect-1').val(),
        architect_2:$('#project-architect-2').val(),
        asst_architect:$('#project-asst-architect').val(),
        structural_inhouse:$('#project-structural-inhouse').val(),
        structural_consultant:$('#project-structural-consultant').val(),
        mep_consultant:$('#project-mep-consultant').val(),
        landscape_consultant:$('#project-landscape-consultant').val(),
        views_3d:$('#project-views-3d').val(),
        working:$('#project-working').val(),
        permission_drawing:$('#project-permission-drawing').val(),
        site_engineer:$('#project-site-engineer').val(),
        status_1:$('#project-status-1').val(),
        status_2:$('#project-status-2').val(),
        status_3:$('#project-status-3').val()
      }).done(function(res){
        if (res && res.success && res.data && res.data.project) {
          const project = res.data.project;
          if (isEdit) {
            const $tr = $('#banka-tms-project-table tbody tr[data-project-id="'+project.id+'"]');
            $tr.replaceWith(projectRowMarkup(project, $tr.index() + 1, detailsIcon, editIcon, deleteIcon));
          } else {
            $('.banka-tms-project-no-result-row').before(projectRowMarkup(project, $('#banka-tms-project-table tbody tr').not('.banka-tms-project-no-result-row').length + 1, detailsIcon, editIcon, deleteIcon));
          }
          refreshProjectSerials(); refreshProjectFilters(); applyProjectFilters(); updateProjectDropdownOptions();
          const $projectModal=$('#banka-tms-project-modal').first();
          const openedFromTask=!!($projectModal.data('opened-from-task') || $projectModal.attr('data-opened-from-task')==='1');
          if(openedFromTask){
            const projectLabel=(project.project_name||'')+((project.address||'')?' ('+project.address+')':'');
            const $taskProject=$('#task-project');
            if(!$taskProject.find('option').filter(function(){return $(this).val()===projectLabel;}).length){
              $taskProject.append($('<option>').val(projectLabel).text(projectLabel));
            }
            $taskProject.val(projectLabel).trigger('change');
            if(window.bankaTmsApp&&Array.isArray(bankaTmsApp.projects)&&bankaTmsApp.projects.indexOf(projectLabel)===-1){
              bankaTmsApp.projects.push(projectLabel);
              bankaTmsApp.projects.sort(function(a,b){return a.localeCompare(b);});
            }
            $projectModal.removeData('opened-from-task').removeAttr('data-opened-from-task data-task-source');
            if(window.bankaTmsTaskNestedModal){ window.bankaTmsTaskNestedModal.closeToTask($projectModal); } else { bankaTmsCloseNestedModal($projectModal); }
            resetProjectForm();
          }else{
            closeModals(); resetProjectForm();
          }
          showProjectMessage((res.data && res.data.message) || 'Project saved successfully', false);
        } else {
          status.text((res.data && res.data.message) || 'Unable to save project');
        }
      }).fail(function(xhr){
        let msg = 'Unable to save project';
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) { msg = xhr.responseJSON.data.message; }
        else if (xhr.status) { msg += ' (HTTP ' + xhr.status + ')'; }
        status.text(msg);
      });
    });
    $('#banka-tms-project-excel').on('click', function(){
      let csv = 'S.No.,Project ID,Project Name,Client Name,Category,Project Type,Address\n';
      $('#banka-tms-project-table tbody tr').not('.banka-tms-project-no-result-row').each(function(){
        const cells = $(this).children('td').slice(0, 7).map(function(){ return '"' + ($(this).text().trim().replace(/"/g, '""')) + '"'; }).get();
        csv += cells.join(',') + '\n';
      });
      const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'projects.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    });
    $('#banka-tms-project-pdf').on('click', function(){ window.print(); });
  }

  if ($('#banka-tms-vendor-form').length) {
    $('#banka-tms-vendor-form').on('submit', function(e){
      e.preventDefault();
      const statusBox = $('#banka-tms-vendor-status');
      statusBox.text('Saving vendor...');
      $.post(bankaTmsApp.ajaxUrl, {
        action: 'banka_tms_app_save_vendor',
        nonce: bankaTmsApp.nonce,
        name: $('#vendor-name').val(),
        email: $('#vendor-email').val(),
        mobile: $('#vendor-mobile').val()
      }).done(function(res){
        if (res.success) {
          statusBox.text('Vendor saved successfully.');
          $('#banka-tms-vendor-form')[0].reset();
          loadVendors();
        } else {
          statusBox.text(res.data && res.data.message ? res.data.message : 'Unable to save vendor');
        }
      });
    });
  }

  function loadVendors() {
    $.post(bankaTmsApp.ajaxUrl, { action: 'banka_tms_app_list_vendors', nonce: bankaTmsApp.nonce }).done(function(res){
      const list = $('#banka-tms-vendor-list');
      if (!res.success || !res.data) { list.html('<p>No vendors found.</p>'); return; }
      const html = '<ul>' + res.data.map(function(vendor){ return '<li class="banka-tms-row"><strong>' + vendor.name + '</strong> - ' + (vendor.email || 'No email') + ' - ' + (vendor.mobile || 'No mobile') + '</li>'; }).join('') + '</ul>';
      list.html(html);
    });
  }
  if ($('#banka-tms-vendor-list').length) { loadVendors(); }
});

jQuery(function($){
  function escProjectMaster(value){ return $('<div>').text(value == null ? '' : String(value)).html(); }
  function renumberProjectMasterRows($page){
    $page.find('.banka-tms-project-master-table tbody tr').not('.banka-tms-project-master-empty').each(function(i){ $(this).children('td').eq(0).text(i+1); });
  }
  $(document).on('click', '.banka-tms-open-project-master-modal', function(){
    const $page = $(this).closest('.banka-tms-project-master-page');
    $('#project-master-id').val('0');
    $('#project-master-key').val($page.data('master'));
    $('#project-master-name').val('');
    $('#project-master-status').text('');
    $('#banka-tms-project-master-title').text('Add ' + ($page.data('master') === 'type' ? 'Project Type' : 'Project Category'));
    $('#banka-tms-project-master-modal').fadeIn(120);
    $('body').addClass('banka-tms-modal-open');
  });
  $(document).on('click', '.banka-tms-project-master-edit', function(){
    const $row = $(this).closest('tr');
    const $page = $(this).closest('.banka-tms-project-master-page');
    $('#project-master-id').val($row.data('id'));
    $('#project-master-key').val($page.data('master'));
    $('#project-master-name').val($row.data('name'));
    $('#project-master-status').text('');
    $('#banka-tms-project-master-title').text('Edit ' + ($page.data('master') === 'type' ? 'Project Type' : 'Project Category'));
    $('#banka-tms-project-master-modal').fadeIn(120);
    $('body').addClass('banka-tms-modal-open');
  });
  $(document).on('submit', '#banka-tms-project-master-form', function(e){
    e.preventDefault();
    const $status = $('#project-master-status');
    $status.text('Saving...');
    $.post(bankaTmsApp.ajaxUrl, {
      action:'banka_tms_app_save_project_master_item', nonce:bankaTmsApp.nonce,
      master:$('#project-master-key').val(), id:$('#project-master-id').val(), name:$('#project-master-name').val()
    }).done(function(res){
      if(res && res.success){ window.location.reload(); }
      else $status.text((res.data && res.data.message) || 'Unable to save');
    }).fail(function(){ $status.text('Unable to save'); });
  });
  $(document).on('click', '.banka-tms-project-master-delete', function(){
    if(!confirm('Delete this entry?')) return;
    const $row=$(this).closest('tr');
    const $page=$(this).closest('.banka-tms-project-master-page');
    $.post(bankaTmsApp.ajaxUrl,{action:'banka_tms_app_delete_project_master_item',nonce:bankaTmsApp.nonce,master:$page.data('master'),id:$row.data('id')}).done(function(res){
      if(res&&res.success){ $row.remove(); renumberProjectMasterRows($page); }
      else alert((res.data&&res.data.message)||'Unable to delete');
    });
  });
  $(document).on('input', '.banka-tms-project-master-search', function(){
    const q=($(this).val()||'').toLowerCase().trim();
    const $page=$(this).closest('.banka-tms-project-master-page'); let shown=0;
    $page.find('.banka-tms-project-master-table tbody tr').not('.banka-tms-project-master-empty').each(function(){ const show=!q||$(this).text().toLowerCase().indexOf(q)!==-1; $(this).toggle(show); if(show)shown++; });
    $page.find('.banka-tms-project-master-empty').toggle(shown===0);
  });

  // Project form: Project Type comes from its master; Category is restricted to Architect and Interior.
  if($('#project-type').length && window.bankaTmsApp){
    const currentType=$('#project-type').val()||'';
    const typeOptions=['<option value="">Select Project Type</option>'];
    (bankaTmsApp.projectTypes||[]).forEach(function(name){ typeOptions.push('<option value="'+escProjectMaster(name)+'">'+escProjectMaster(name)+'</option>'); });
    $('#project-type').html(typeOptions.join('')).val(currentType);

    const currentCategory=$('#project-category').val()||'';
    const allowed=(bankaTmsApp.projectCategories||[]).filter(function(name){ return ['architect','interior'].indexOf(String(name).toLowerCase())!==-1; });
    const categories=allowed.length?allowed:['Architect','Interior'];
    const catOptions=['<option value="">Select Category</option>'];
    categories.forEach(function(name){ catOptions.push('<option value="'+escProjectMaster(name)+'">'+escProjectMaster(name)+'</option>'); });
    $('#project-category').html(catOptions.join('')).val(currentCategory);
    $('#project-type,#project-category').prop('required',true);
  }

  function applyActionLogFilters(){
    const q = ($('#banka-tms-action-log-search').val() || '').toLowerCase().trim();
    const action = $('#banka-tms-action-log-filter-action').val() || [];
    const assignee = $('#banka-tms-action-log-filter-assignee').val() || [];
    const owner = $('#banka-tms-action-log-filter-owner').val() || [];
    const project = $('#banka-tms-action-log-filter-project').val() || [];
    const fromDate = $('#banka-tms-action-log-filter-from').val() || '';
    const toDate = $('#banka-tms-action-log-filter-to').val() || '';
    let visibleCount = 0;

    $('#banka-tms-action-log-body tr').not('.banka-tms-action-log-no-result-row, .banka-tms-no-result-row').each(function(){
      const $row = $(this);
      const rowText = $row.text().toLowerCase();
      const rowAction = ($row.data('action') || '').toString();
      const rowAssignee = ($row.data('assignee') || '').toString();
      const rowOwner = ($row.data('owner') || '').toString();
      const rowProject = ($row.data('project') || '').toString();
      const rowDate = ($row.data('date') || '').toString();
      let show = true;
      if (q && rowText.indexOf(q) === -1) show = false;
      if (action.length && action.indexOf(rowAction) === -1) show = false;
      if (assignee.length && assignee.indexOf(rowAssignee) === -1) show = false;
      if (owner.length && owner.indexOf(rowOwner) === -1) show = false;
      if (project.length && project.indexOf(rowProject) === -1) show = false;
      if (fromDate && rowDate && rowDate < fromDate) show = false;
      if (toDate && rowDate && rowDate > toDate) show = false;
      $row.toggle(show);
      if (show) visibleCount++;
    });
    $('.banka-tms-action-log-no-result-row').toggle(visibleCount === 0);
  }

  if ($('#banka-tms-action-log-table').length) {
    $('#banka-tms-toggle-action-log-filters').on('click', function(){
      const panel = $('#banka-tms-advanced-action-log-filters');
      const isOpen = panel.is(':visible');
      panel.stop(true, true).slideToggle(160);
      $(this).attr('aria-expanded', isOpen ? 'false' : 'true').toggleClass('active', !isOpen);
    });
    $('#banka-tms-action-log-search, #banka-tms-action-log-filter-action, #banka-tms-action-log-filter-assignee, #banka-tms-action-log-filter-owner, #banka-tms-action-log-filter-project, #banka-tms-action-log-filter-from, #banka-tms-action-log-filter-to').on('input change', applyActionLogFilters);
    $('#banka-tms-clear-action-log-filters').on('click', function(){
      $('#banka-tms-action-log-search').val('');
      $('#banka-tms-action-log-filter-action, #banka-tms-action-log-filter-assignee, #banka-tms-action-log-filter-owner, #banka-tms-action-log-filter-project').val([]).trigger('change');
      $('#banka-tms-action-log-filter-from, #banka-tms-action-log-filter-to').val('');
      applyActionLogFilters();
    });
    applyActionLogFilters();

    $('#banka-tms-action-log-excel').on('click', function(){
      let csv = [];
      $('#banka-tms-action-log-table tr:visible').each(function(){
        const row = [];
        $(this).find('th,td').each(function(){
          row.push('"' + ($(this).text().trim().replace(/"/g, '""')) + '"');
        });
        if (row.length) csv.push(row.join(','));
      });
      const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'action-log.csv';
      link.click();
      URL.revokeObjectURL(link.href);
    });

    $('#banka-tms-action-log-pdf').on('click', function(){
      const params = new URLSearchParams();
      params.set('action', 'banka_tms_action_log_pdf');
      params.set('search', $('#banka-tms-action-log-search').val() || '');
      params.set('action_filter', $('#banka-tms-action-log-filter-action').val() || '');
      params.set('assignee', $('#banka-tms-action-log-filter-assignee').val() || '');
      params.set('owner', $('#banka-tms-action-log-filter-owner').val() || '');
      params.set('project', $('#banka-tms-action-log-filter-project').val() || '');
      params.set('from', $('#banka-tms-action-log-filter-from').val() || '');
      params.set('to', $('#banka-tms-action-log-filter-to').val() || '');
      window.location.href = (bankaTmsApp.taskPdfUrl || '') + '?' + params.toString();
    });
  }

  // Searchable and alphabetically sorted dropdowns throughout TaskPro.
  const fixedOrderSelects = {
    'banka-tms-filter-status': true,
    'quick-update-status': true,
    'task-status': true,
    'task-priority': true,
    'banka-tms-filter-priority': true,
    'user-role': true
  };

  function sortNativeSelect($select){
    const id = $select.attr('id') || '';
    if (fixedOrderSelects[id] || $select.is('[data-keep-order]')) return;
    const selectedValue = $select.val();
    const options = $select.find('option').get();
    if (options.length < 3) return;
    let placeholder = null;
    const sortable = [];
    options.forEach(function(option, index){
      if (index === 0 && (option.value === '' || option.disabled)) placeholder = option;
      else sortable.push(option);
    });
    const sorted = sortable.slice().sort(function(a,b){ return $(a).text().trim().localeCompare($(b).text().trim(), undefined, {sensitivity:'base'}); });
    const alreadySorted = sortable.every(function(option, index){ return option === sorted[index]; });
    if (alreadySorted) return;
    $select.empty();
    if (placeholder) $select.append(placeholder);
    sorted.forEach(function(option){ $select.append(option); });
    $select.val(selectedValue);
  }

  function enhanceMultiSelect(select){
    const $select = $(select);
    if ($select.data('tms-multi-ready')) return;
    $select.data('tms-multi-ready', true);

    const placeholder = $select.attr('data-multi-placeholder') || 'Select';
    const $wrapper = $('<div class="banka-tms-multi-select"></div>');
    const $button = $('<button type="button" class="banka-tms-multi-select-button"><span></span><b>⌃</b></button>');
    const $panel = $('<div class="banka-tms-multi-select-panel" style="display:none"><input type="text" class="banka-tms-multi-select-search" placeholder="Search..."><div class="banka-tms-multi-select-tools"><button type="button" class="banka-tms-multi-all">☷ ALL</button><button type="button" class="banka-tms-multi-clear">⌫ CLEAR</button></div><div class="banka-tms-multi-select-options"></div></div>');
    $select.wrap($wrapper);
    const $actualWrapper = $select.parent();
    $actualWrapper.append($button);
    $('body').append($panel);
    $select.addClass('banka-tms-native-select-hidden');

    function values(){ return ($select.val() || []).filter(function(v){ return String(v) !== ''; }); }
    function syncButton(){
      const selected = values();
      let label = placeholder;
      if (selected.length === 1) label = $select.find('option[value="'+String(selected[0]).replace(/"/g,'\\"')+'"]').text() || selected[0];
      else if (selected.length > 1) label = selected.length + ' selected';
      $button.find('span').text(label);
    }
    function rebuildOptions(){
      const selected = values();
      const html=[];
      $select.find('option').each(function(){
        const value=String(this.value);
        if (!value) return;
        const text=$(this).text();
        const checked=selected.indexOf(value)!==-1;
        html.push('<label class="banka-tms-multi-select-option'+(checked?' selected':'')+'"><input type="checkbox" value="'+$('<div>').text(value).html()+'"'+(checked?' checked':'')+'><span>'+ $('<div>').text(text).html() +'</span></label>');
      });
      $panel.find('.banka-tms-multi-select-options').html(html.join(''));
      syncButton();
    }
    function positionPanel(){
      const rect=$button[0].getBoundingClientRect();
      const width=Math.max(rect.width,250);
      const left=Math.min(rect.left, Math.max(8, window.innerWidth-width-8));
      $panel.css({left:left+'px',top:(rect.bottom+4)+'px',width:width+'px'});
    }
    function closePanel(){ $panel.hide(); $actualWrapper.removeClass('open'); }
    function openPanel(){
      $('.banka-tms-searchable-select-panel,.banka-tms-multi-select-panel').not($panel).hide();
      $('.banka-tms-searchable-select,.banka-tms-multi-select').not($actualWrapper).removeClass('open');
      rebuildOptions(); positionPanel(); $panel.show(); $actualWrapper.addClass('open');
      $panel.find('.banka-tms-multi-select-search').val('').trigger('input').focus();
    }
    function commit(selected){
      $select.val(selected).trigger('change');
      rebuildOptions();
    }

    $button.on('click',function(e){ e.stopPropagation(); $panel.is(':visible')?closePanel():openPanel(); });
    $panel.on('click',function(e){ e.stopPropagation(); });
    $panel.on('input','.banka-tms-multi-select-search',function(){
      const q=($(this).val()||'').toLowerCase().trim();
      $panel.find('.banka-tms-multi-select-option').each(function(){ $(this).toggle(!q||$(this).text().toLowerCase().indexOf(q)!==-1); });
    });
    $panel.on('change','.banka-tms-multi-select-option input',function(){
      const selected=$panel.find('.banka-tms-multi-select-option input:checked').map(function(){return this.value;}).get();
      commit(selected);
    });
    $panel.on('click','.banka-tms-multi-all',function(){
      const all=$select.find('option').map(function(){return this.value||null;}).get().filter(Boolean);
      commit(all);
    });
    $panel.on('click','.banka-tms-multi-clear',function(){ commit([]); });
    $select.on('change',syncButton);
    $(window).on('resize scroll',function(){ if($panel.is(':visible')) positionPanel(); });
    $(document).on('click',closePanel);
    syncButton();
  }

  function enhanceSelect(select){
    const $select = $(select);
    if ($select.data('tms-searchable-ready') || $select.prop('multiple') || $select.attr('size') || $select.hasClass('banka-tms-no-enhance')) return;
    $select.data('tms-searchable-ready', true);
    sortNativeSelect($select);

    const $wrapper = $('<div class="banka-tms-searchable-select"></div>');
    const $button = $('<button type="button" class="banka-tms-searchable-select-button"><span></span><b>⌄</b></button>');
    const $panel = $('<div class="banka-tms-searchable-select-panel" style="display:none"><input type="text" class="banka-tms-searchable-select-search" placeholder="Search..."><div class="banka-tms-searchable-select-options"></div></div>');
    $select.wrap($wrapper);
    const $actualWrapper = $select.parent();
    $actualWrapper.append($button);
    $('body').append($panel);
    $select.addClass('banka-tms-native-select-hidden');

    function selectedText(){
      const $selected = $select.find('option:selected');
      return $selected.length ? $selected.text() : ($select.find('option').first().text() || 'Select');
    }
    function syncButton(){ $button.find('span').text(selectedText()); }
    function rebuildOptions(){
      sortNativeSelect($select);
      const selected = String($select.val() == null ? '' : $select.val());
      const html = [];
      $select.find('option').each(function(){
        const value = String(this.value);
        const text = $(this).text();
        html.push('<button type="button" class="banka-tms-searchable-select-option'+(value===selected?' selected':'')+'" data-value="'+$('<div>').text(value).html()+'">'+$('<div>').text(text).html()+'</button>');
      });
      $panel.find('.banka-tms-searchable-select-options').html(html.join(''));
      syncButton();
    }
    function positionPanel(){
      const rect = $button[0].getBoundingClientRect();
      $panel.css({left:rect.left+'px', top:(rect.bottom+4)+'px', width:Math.max(rect.width,220)+'px'});
    }
    function closePanel(){ $panel.hide(); $actualWrapper.removeClass('open'); }
    function openPanel(){
      $('.banka-tms-searchable-select-panel').not($panel).hide();
      $('.banka-tms-searchable-select').not($actualWrapper).removeClass('open');
      rebuildOptions(); positionPanel();
      $panel.show(); $actualWrapper.addClass('open');
      const $search = $panel.find('.banka-tms-searchable-select-search');
      $search.val('').trigger('input').focus();
    }

    $button.on('click', function(e){ e.stopPropagation(); $panel.is(':visible') ? closePanel() : openPanel(); });
    $panel.on('click', function(e){ e.stopPropagation(); });
    $panel.on('input', '.banka-tms-searchable-select-search', function(){
      const q = ($(this).val() || '').toLowerCase().trim();
      $panel.find('.banka-tms-searchable-select-option').each(function(){
        $(this).toggle(!q || $(this).text().toLowerCase().indexOf(q) !== -1);
      });
    });
    $panel.on('click', '.banka-tms-searchable-select-option', function(){
      $select.val($(this).attr('data-value')).trigger('change');
      syncButton(); closePanel();
    });
    $select.on('change', syncButton);
    $(window).on('resize scroll', function(){ if ($panel.is(':visible')) positionPanel(); });
    $(document).on('click', closePanel);

    const observer = new MutationObserver(function(){ rebuildOptions(); });
    observer.observe(select, {childList:true, subtree:true});
    rebuildOptions();
  }

  function enhanceAllSelects(root){
    $(root || document).find('select').each(function(){
      if ($(this).prop('multiple')) enhanceMultiSelect(this);
      else enhanceSelect(this);
    });
  }
  enhanceAllSelects(document);
  const selectObserver = new MutationObserver(function(mutations){
    mutations.forEach(function(mutation){
      mutation.addedNodes.forEach(function(node){
        if (node.nodeType !== 1) return;
        if (node.matches && node.matches('select')) { if ($(node).prop('multiple')) enhanceMultiSelect(node); else enhanceSelect(node); }
        enhanceAllSelects(node);
      });
    });
  });

  selectObserver.observe(document.body, {childList:true, subtree:true});


  const paginationTableIds = [
    '#banka-tms-task-data-table',
    '#banka-tms-user-data-table',
    '#banka-tms-category-table',
    '#banka-tms-designation-table',
    '#banka-tms-task-master-table',
    '#banka-tms-project-table',
    '#banka-tms-action-log-table'
  ];

  function paginationRows($table){
    return $table.find('tbody > tr').filter(function(){
      const $row = $(this);
      return !$row.hasClass('banka-tms-no-result-row') &&
             !$row.hasClass('banka-tms-action-log-no-result-row') &&
             !$row.hasClass('banka-tms-project-no-result-row') &&
             !$row.hasClass('banka-tms-category-no-result-row') &&
             !$row.hasClass('banka-tms-task-master-no-result-row');
    });
  }

  function paginationVisibleCandidates($table){
    const $rows = paginationRows($table);
    $rows.filter('[data-tms-page-hidden="1"]')
      .not('.banka-tms-project-filter-hidden, .banka-tms-action-log-filter-hidden')
      .show().removeAttr('data-tms-page-hidden');
    return $rows.filter(function(){
      return !$(this).hasClass('banka-tms-project-filter-hidden') &&
             !$(this).hasClass('banka-tms-action-log-filter-hidden') &&
             $(this).css('display') !== 'none';
    });
  }

  function paginationPageItems(current, total){
    if (total <= 7) return Array.from({length:total}, (_,i)=>i+1);
    if (current <= 4) return [1,2,3,4,5,'...',total];
    if (current >= total-3) return [1,'...',total-4,total-3,total-2,total-1,total];
    return [1,'...',current-1,current,current+1,'...',total];
  }

  function renderPagination($table, resetPage){
    let state = $table.data('tmsPagination') || {page:1, perPage:20};
    if (resetPage) state.page = 1;
    const $rows = paginationVisibleCandidates($table);
    const totalRows = $rows.length;
    const totalPages = Math.max(1, Math.ceil(totalRows/state.perPage));
    if (state.page > totalPages) state.page = totalPages;
    $table.data('tmsPagination', state);

    $rows.each(function(index){
      const visible = index >= (state.page-1)*state.perPage && index < state.page*state.perPage;
      if (visible) $(this).show().removeAttr('data-tms-page-hidden');
      else $(this).hide().attr('data-tms-page-hidden','1');
    });

    let $pager = $table.closest('.banka-tms-table-wrap, .banka-tms-advanced-table-wrap').next('.banka-tms-pagination');
    if (!$pager.length) {
      $pager = $('<div class="banka-tms-pagination"></div>');
      $table.closest('.banka-tms-table-wrap, .banka-tms-advanced-table-wrap').after($pager);
    }

    const items = paginationPageItems(state.page,totalPages);
    let html = '<div class="banka-tms-pagination-left"><span>Rows</span><select class="banka-tms-pagination-size banka-tms-no-enhance">';
    [10,20,50,100].forEach(function(n){ html += '<option value="'+n+'"'+(state.perPage===n?' selected':'')+'>'+n+'</option>'; });
    html += '</select></div><div class="banka-tms-pagination-pages">';
    html += '<button type="button" class="banka-tms-page-arrow" data-page="'+Math.max(1,state.page-1)+'" '+(state.page===1?'disabled':'')+' aria-label="Previous">‹</button>';
    items.forEach(function(item){
      if (item === '...') html += '<span class="banka-tms-page-ellipsis">...</span>';
      else html += '<button type="button" class="banka-tms-page-number '+(item===state.page?'is-active':'')+'" data-page="'+item+'">'+item+'</button>';
    });
    html += '<button type="button" class="banka-tms-page-arrow" data-page="'+Math.min(totalPages,state.page+1)+'" '+(state.page===totalPages?'disabled':'')+' aria-label="Next">›</button></div>';
    $pager.html(html).toggle(totalRows > 0);
  }

  function initPagination($table){
    if (!$table.length || $table.data('tms-pagination-ready')) return;
    $table.data('tms-pagination-ready',true).data('tmsPagination',{page:1,perPage:20});
    renderPagination($table,true);
    const $scope = $table.closest('.banka-tms-card, .banka-tms-user-page, .banka-tms-project-page, .banka-tms-action-log-page');
    $scope.on('input change', 'input,select', function(){
      if ($(this).hasClass('banka-tms-pagination-size')) return;
      paginationRows($table).filter('[data-tms-page-hidden="1"]')
        .not('.banka-tms-project-filter-hidden, .banka-tms-action-log-filter-hidden')
        .show().removeAttr('data-tms-page-hidden');
      setTimeout(function(){
        if ($table.is('#banka-tms-project-table')) applyProjectFilters();
        if ($table.is('#banka-tms-action-log-table')) applyActionLogFilters();
        renderPagination($table,true);
      }, 30);
    });
  }

  paginationTableIds.forEach(function(selector){ initPagination($(selector)); });

  $(document).on('change','.banka-tms-pagination-size',function(){
    const $pager=$(this).closest('.banka-tms-pagination');
    const $table=$pager.prev('.banka-tms-table-wrap, .banka-tms-advanced-table-wrap').find('table').first();
    const state=$table.data('tmsPagination')||{page:1,perPage:20};
    state.perPage=parseInt($(this).val(),10)||20; state.page=1;
    $table.data('tmsPagination',state); renderPagination($table,false);
  });

  $(document).on('click','.banka-tms-pagination [data-page]',function(){
    if ($(this).prop('disabled')) return;
    const $pager=$(this).closest('.banka-tms-pagination');
    const $table=$pager.prev('.banka-tms-table-wrap, .banka-tms-advanced-table-wrap').find('table').first();
    const state=$table.data('tmsPagination')||{page:1,perPage:20};
    state.page=parseInt($(this).attr('data-page'),10)||1;
    $table.data('tmsPagination',state); renderPagination($table,false);
  });

  const paginationObserver = new MutationObserver(function(){
    clearTimeout(window.bankaTmsPaginationTimer);
    window.bankaTmsPaginationTimer=setTimeout(function(){
      paginationTableIds.forEach(function(selector){ const $table=$(selector); if($table.length) renderPagination($table,false); });
    },80);
  });
  paginationTableIds.forEach(function(selector){ const body=$(selector).find('tbody').get(0); if(body) paginationObserver.observe(body,{childList:true}); });



  // v3.0.21 - Clickable sorting for every data table.
  function bankaTmsSortValue(text){
    const value = String(text || '').replace(/\s+/g,' ').trim();
    if (!value) return {type:'text', value:''};
    const dateMatch = value.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})(?:\s+(\d{1,2}):(\d{2}))?/);
    if (dateMatch) {
      return {type:'number', value:new Date(Number(dateMatch[3]),Number(dateMatch[2])-1,Number(dateMatch[1]),Number(dateMatch[4]||0),Number(dateMatch[5]||0)).getTime()};
    }
    const normalizedNumber = value.replace(/,/g,'');
    if (/^-?\d+(?:\.\d+)?$/.test(normalizedNumber)) return {type:'number', value:Number(normalizedNumber)};
    return {type:'text', value:value.toLocaleLowerCase()};
  }

  function bankaTmsRefreshSerialNumbers($table){
    const firstHeading = $table.find('thead th').first().text().replace(/\s+/g,' ').trim().toLowerCase();
    if (firstHeading !== 's.no.' && firstHeading !== 's.no' && firstHeading !== 'sl.' && firstHeading !== 'sl') return;
    let serial=1;
    paginationRows($table).each(function(){
      $(this).children('td').first().text(serial++);
    });
  }

  $('.banka-tms-table').each(function(){
    const $table=$(this);
    $table.find('thead th').each(function(index){
      const $th=$(this);
      const heading=$th.text().replace(/\s+/g,' ').trim().toLowerCase();
      if (!heading || heading === 'actions' || $th.find('input[type="checkbox"]').length) return;
      $th.addClass('banka-tms-sortable-header').attr({'tabindex':'0','role':'button','aria-sort':'none','data-sort-column':index});
    });
  });

  $(document).on('click keydown','.banka-tms-sortable-header',function(event){
    if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
    if (event.type === 'keydown') event.preventDefault();
    const $th=$(this), $table=$th.closest('table'), column=Number($th.attr('data-sort-column'));
    const current=$th.attr('aria-sort');
    const direction=current === 'ascending' ? 'descending' : 'ascending';
    const $tbody=$table.children('tbody');
    const rows=paginationRows($table).get();
    rows.sort(function(a,b){
      const av=bankaTmsSortValue($(a).children('td').eq(column).text());
      const bv=bankaTmsSortValue($(b).children('td').eq(column).text());
      let result;
      if (av.type === 'number' && bv.type === 'number') result=av.value-bv.value;
      else result=String(av.value).localeCompare(String(bv.value),undefined,{numeric:true,sensitivity:'base'});
      return direction === 'ascending' ? result : -result;
    });
    $table.find('thead th').removeClass('is-sort-asc is-sort-desc').attr('aria-sort','none');
    $th.addClass(direction === 'ascending' ? 'is-sort-asc' : 'is-sort-desc').attr('aria-sort',direction);
    rows.forEach(function(row){ $tbody.append(row); });
    bankaTmsRefreshSerialNumbers($table);
    if ($table.data('tms-pagination-ready')) renderPagination($table,true);
  });

  const requestedForm = new URLSearchParams(window.location.search).get('open_form');
  if (requestedForm) {
    setTimeout(function(){
      if (requestedForm === 'task' && $('#banka-tms-open-task-modal').length) {
        $('#banka-tms-open-task-modal').trigger('click');
      } else if (requestedForm === 'user' && $('#banka-tms-open-user-modal').length) {
        $('#banka-tms-open-user-modal').trigger('click');
      } else if (requestedForm === 'project' && $('#banka-tms-open-project-modal').length) {
        $('#banka-tms-open-project-modal').trigger('click');
      }
      const cleanUrl = new URL(window.location.href);
      cleanUrl.searchParams.delete('open_form');
      window.history.replaceState({}, document.title, cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
    }, 120);
  }

  $(document).on('change', '#banka-tms-organization-logo-file', function(){
    const file = this.files && this.files[0] ? this.files[0] : null;
    if (!file) return;
    if (!file.type.match(/^image\//)) {
      alert('Please select an image file.');
      this.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = function(e){
      $('#banka-tms-logo-preview').html('<img src="'+e.target.result+'" alt="Organization Logo" />');
    };
    reader.readAsDataURL(file);
  });


  function bankaTmsCloseNestedModal($modal){
    $modal.stop(true,true).fadeOut(120,function(){
      $(this).removeClass('banka-tms-nested-modal');
      if ($('#banka-tms-task-modal:visible').length) {
        $('#banka-tms-task-modal').css('display','flex').show();
        $('body').addClass('banka-tms-modal-open');
      }
    });
  }

  $(document).on('click', '.banka-tms-quick-add-category', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    const $modal=$('#banka-tms-quick-category-modal');
    if(!$modal.length) return false;
    $('#banka-tms-quick-category-name').val('');
    $('#banka-tms-quick-category-status').text('');
    $modal.addClass('banka-tms-nested-modal').css('display','flex').hide().fadeIn(120);
    $('body').addClass('banka-tms-modal-open');
    setTimeout(function(){ $('#banka-tms-quick-category-name').trigger('focus'); },80);
    return false;
  });

  $(document).on('click', '#banka-tms-quick-category-modal [data-quick-category-close], #banka-tms-quick-category-modal > .banka-tms-modal-backdrop', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    bankaTmsCloseNestedModal($('#banka-tms-quick-category-modal'));
    return false;
  });

  $(document).on('submit', '#banka-tms-quick-category-form', function(e){
    e.preventDefault();
    const name=($('#banka-tms-quick-category-name').val()||'').trim();
    const $status=$('#banka-tms-quick-category-status');
    if(!name){ $status.text('Category name is required'); return; }
    $status.text('Saving...');
    $.post(bankaTmsApp.ajaxUrl,{action:'banka_tms_app_save_category',nonce:bankaTmsApp.nonce,name:name})
      .done(function(res){
        if(res&&res.success&&res.data&&res.data.category){
          const categoryName=res.data.category.name;
          const $select=$('#task-category');
          if(!$select.find('option').filter(function(){return $(this).val()===categoryName;}).length){
            $select.append($('<option>').val(categoryName).text(categoryName));
          }
          $select.val(categoryName).trigger('change');
          if(window.bankaTmsApp&&Array.isArray(bankaTmsApp.categories)&&bankaTmsApp.categories.indexOf(categoryName)===-1){
            bankaTmsApp.categories.push(categoryName);
            bankaTmsApp.categories.sort(function(a,b){return a.localeCompare(b);});
          }
          bankaTmsCloseNestedModal($('#banka-tms-quick-category-modal'));
        }else{
          $status.text((res&&res.data&&res.data.message)?res.data.message:'Unable to add category');
        }
      }).fail(function(xhr){
        const msg=xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:'Unable to add category';
        $status.text(msg);
      });
  });

  $(document).on('click', '.banka-tms-quick-add-project', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    const $projectModal=$('#banka-tms-project-modal').first();
    if(!$projectModal.length) return false;
    resetProjectForm();
    $projectModal.data('opened-from-task',1).addClass('banka-tms-nested-modal').css('display','flex').hide().fadeIn(120);
    $('body').addClass('banka-tms-modal-open');
    return false;
  });

  $(document).on('click', '#banka-tms-project-modal.banka-tms-nested-modal [data-close-modal], #banka-tms-project-modal.banka-tms-nested-modal > .banka-tms-modal-backdrop', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    const $modal=$('#banka-tms-project-modal.banka-tms-nested-modal').first();
    $modal.removeData('opened-from-task');
    bankaTmsCloseNestedModal($modal);
    return false;
  });

  $(document).on('click', '#banka-tms-quick-add-task-master', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    const $quickModal = $('#banka-tms-quick-task-master-modal');
    if (!$quickModal.length) {
      return false;
    }
    $('#banka-tms-quick-task-master-name').val('');
    $('#banka-tms-quick-task-master-status').text('');
    $quickModal.addClass('banka-tms-nested-modal').css('display','flex').hide().fadeIn(120);
    $('body').addClass('banka-tms-modal-open');
    setTimeout(function(){ $('#banka-tms-quick-task-master-name').trigger('focus'); }, 80);
    return false;
  });

  $(document).on('click', '#banka-tms-quick-task-master-modal [data-close-modal], #banka-tms-quick-task-master-modal > .banka-tms-modal-backdrop', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    $('#banka-tms-quick-task-master-modal').fadeOut(120, function(){
      $(this).removeClass('banka-tms-nested-modal');
      if (!$('#banka-tms-task-modal:visible').length) {
        $('body').removeClass('banka-tms-modal-open');
      }
    });
    return false;
  });

  $(document).on('submit', '#banka-tms-quick-task-master-form', function(e){
    e.preventDefault();
    const name = ($('#banka-tms-quick-task-master-name').val() || '').trim();
    const $status = $('#banka-tms-quick-task-master-status');
    if (!name) { $status.text('Task name is required'); return; }
    $status.text('Saving...');
    $.post(bankaTmsApp.ajaxUrl, {
      action: 'banka_tms_app_save_task_master',
      nonce: bankaTmsApp.nonce,
      name: name
    }).done(function(res){
      if (res && res.success && res.data && res.data.task) {
        const taskName = res.data.task.name;
        const $select = $('#task-title');
        if (!$select.find('option').filter(function(){ return $(this).val() === taskName; }).length) {
          $select.append($('<option>').val(taskName).text(taskName));
        }
        $select.val(taskName).trigger('change');
        if (window.bankaTmsApp && Array.isArray(bankaTmsApp.taskMasters) && bankaTmsApp.taskMasters.indexOf(taskName) === -1) {
          bankaTmsApp.taskMasters.push(taskName);
          bankaTmsApp.taskMasters.sort(function(a,b){ return a.localeCompare(b); });
        }
        const $quickModal = $('#banka-tms-quick-task-master-modal');
        $quickModal.stop(true, true).fadeOut(120, function(){
          $(this).removeClass('banka-tms-nested-modal');
        });
        $('#banka-tms-task-modal').stop(true, true).css('display','flex').show();
        $('body').addClass('banka-tms-modal-open');
        $('#banka-tms-quick-task-master-name').val('');
        $status.text('');
      } else {
        $status.text((res && res.data && res.data.message) ? res.data.message : 'Unable to add task');
      }
    }).fail(function(){ $status.text('Unable to add task'); });
  });

  $(document).on('click', '.banka-tms-calendar-trigger', function(){
    const input = $(this).siblings('input[type="date"]').get(0);
    if (!input) return;
    if (typeof input.showPicker === 'function') input.showPicker();
    else { input.focus(); input.click(); }
  });

});


/* v2.5.7 - plugin-only browser-assisted scheduler */
(function () {
    'use strict';
    if (typeof window.bankaTmsApp === 'undefined' || !window.bankaTmsApp.ajaxUrl) {
        return;
    }

    var schedulerBusy = false;
    function runTaskProScheduler() {
        if (schedulerBusy || document.hidden) {
            return;
        }
        schedulerBusy = true;
        var body = new URLSearchParams();
        body.append('action', 'banka_tms_app_run_internal_scheduler');
        body.append('nonce', window.bankaTmsApp.nonce || '');

        fetch(window.bankaTmsApp.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).catch(function () {
            // Silent fallback: normal WP-Cron remains active.
        }).finally(function () {
            schedulerBusy = false;
        });
    }

    window.setTimeout(runTaskProScheduler, 5000);
    window.setInterval(runTaskProScheduler, 60000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            runTaskProScheduler();
        }
    });
})();

(function($){ if(!$){ return; } $(document).on('change','#quick-update-status',function(){const completed=$(this).val()==='Completed'; $('#banka-tms-next-action-wrap').toggle(!completed); if(completed) $('#quick-update-next-action-date').val('');}); })(window.jQuery);

/* v3.0.0 bulk template upload */
(function(){
  function esc(value){ return String(value == null ? '' : value).replace(/[&<>'"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];}); }
  document.addEventListener('click', function(e){
    var uploadBtn=e.target.closest('.banka-tms-bulk-upload-button');
    if(uploadBtn){
      var input=document.querySelector('.banka-tms-bulk-file[data-bulk-type="'+uploadBtn.dataset.bulkType+'"]');
      if(input){ input.value=''; input.click(); }
      return;
    }
    if(e.target.closest('#banka-tms-bulk-done')){
      var modal=document.getElementById('banka-tms-bulk-result-modal');
      if(modal) modal.style.display='none';
      if(window.bankaTmsBulkInserted>0) window.location.reload();
    }
  });
  document.addEventListener('change', function(e){
    var input=e.target.closest('.banka-tms-bulk-file');
    if(!input || !input.files || !input.files[0]) return;
    var btn=document.querySelector('.banka-tms-bulk-upload-button[data-bulk-type="'+input.dataset.bulkType+'"]');
    var original=btn ? btn.innerHTML : '';
    if(btn){ btn.disabled=true; btn.querySelector('span').textContent='Uploading...'; }
    var form=new FormData();
    form.append('action','banka_tms_bulk_upload');
    form.append('nonce',window.bankaTmsApp ? bankaTmsApp.nonce : '');
    form.append('type',input.dataset.bulkType);
    form.append('file',input.files[0]);
    fetch(window.bankaTmsApp ? bankaTmsApp.ajaxUrl : '/wp-admin/admin-ajax.php',{method:'POST',body:form,credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(res){
        if(!res || !res.success) throw new Error(res && res.data && res.data.message ? res.data.message : 'Upload failed');
        var data=res.data||{}, modal=document.getElementById('banka-tms-bulk-result-modal'), body=document.getElementById('banka-tms-bulk-errors'), summary=document.getElementById('banka-tms-bulk-summary');
        window.bankaTmsBulkInserted=parseInt(data.inserted||0,10);
        if(summary){ summary.className='banka-tms-status '+(data.failed?'is-error':'is-success'); summary.textContent=data.message||''; }
        if(body){
          body.innerHTML='';
          (data.errors||[]).forEach(function(row){ body.insertAdjacentHTML('beforeend','<tr><td>'+esc(row.row)+'</td><td>'+esc(row.record)+'</td><td>'+esc(row.error)+'</td></tr>'); });
          if(!(data.errors||[]).length) body.innerHTML='<tr><td colspan="3" class="banka-tms-bulk-success-cell">No validation errors.</td></tr>';
        }
        if(modal) modal.style.display='flex';
      })
      .catch(function(err){
        window.bankaTmsBulkInserted=0;
        var modal=document.getElementById('banka-tms-bulk-result-modal'), summary=document.getElementById('banka-tms-bulk-summary'), body=document.getElementById('banka-tms-bulk-errors');
        if(summary){ summary.className='banka-tms-status is-error'; summary.textContent=err.message||'Upload failed'; }
        if(body) body.innerHTML='<tr><td colspan="3">'+esc(err.message||'Upload failed')+'</td></tr>';
        if(modal) modal.style.display='flex';
      })
      .finally(function(){ if(btn){ btn.disabled=false; btn.innerHTML=original; } });
  });
})();


// v3.0.39 Dashboard activity reports.
(function(){
  function closeActivityPeriodModal(){
    var modal=document.getElementById('banka-tms-activity-period-modal');
    if(modal) modal.style.display='none';
    document.body.classList.remove('banka-tms-modal-open');
  }
  document.addEventListener('click',function(e){
    if(e.target.closest('#banka-tms-open-activity-period-report')){
      var modal=document.getElementById('banka-tms-activity-period-modal');
      var today=new Date().toISOString().slice(0,10);
      var to=document.getElementById('banka-tms-report-to');
      if(to && !to.value) to.value=today;
      if(modal) modal.style.display='block';
      document.body.classList.add('banka-tms-modal-open');
    }
    if(e.target.closest('#banka-tms-activity-period-modal [data-close-modal]') || e.target.matches('#banka-tms-activity-period-modal .banka-tms-modal-backdrop')) closeActivityPeriodModal();
  });
  document.addEventListener('submit',function(e){
    if(!e.target.matches('#banka-tms-activity-period-form')) return;
    e.preventDefault();
    var from=document.getElementById('banka-tms-report-from').value;
    var to=document.getElementById('banka-tms-report-to').value;
    var status=document.getElementById('banka-tms-activity-period-status');
    if(!from || !to){ if(status) status.textContent='Please select both dates.'; return; }
    if(from>to){ if(status) status.textContent='From Date cannot be after To Date.'; return; }
    var base=(window.bankaTmsApp && bankaTmsApp.taskPdfUrl) ? bankaTmsApp.taskPdfUrl : '/wp-admin/admin-post.php';
    var params=new URLSearchParams({action:'banka_tms_action_log_pdf',from:from,to:to,group:'assignee',report_title:'Periodic Activity Reports',admin_only:'1'});
    window.location.href=base+'?'+params.toString();
    closeActivityPeriodModal();
  });
})();


/* v3.0.46 - reliable mobile filter toggle and selected-task actions */
(function(){
  'use strict';

  function setVisible(element, visible){
    if (!element) return;
    element.classList.toggle('banka-tms-force-hidden', !visible);
    element.classList.toggle('banka-tms-force-visible', visible);
    element.hidden = !visible;
    element.style.display = visible ? '' : 'none';
  }

  function updateTaskBulkActions(){
    var bar = document.getElementById('banka-tms-bulk-action-bar');
    if (!bar) return;
    var checked = document.querySelectorAll('.banka-tms-row-check:checked').length;
    var count = document.getElementById('banka-tms-selected-count');
    if (count) count.textContent = checked + ' task(s) selected';
    setVisible(bar, checked > 0);
  }

  function initialiseTaskMobileControls(){
    var filters = document.getElementById('banka-tms-advanced-task-filters');
    var toggle = document.getElementById('banka-tms-toggle-task-filters');
    var bar = document.getElementById('banka-tms-bulk-action-bar');

    if (filters) {
      setVisible(filters, false);
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.classList.remove('active');
      }
    }
    if (bar) setVisible(bar, false);
    updateTaskBulkActions();
  }

  document.addEventListener('click', function(event){
    var toggle = event.target.closest('#banka-tms-toggle-task-filters');
    if (!toggle) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    var filters = document.getElementById('banka-tms-advanced-task-filters');
    if (!filters) return;
    var open = filters.classList.contains('banka-tms-force-visible') && !filters.hidden;
    setVisible(filters, !open);
    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
    toggle.classList.toggle('active', !open);
  }, true);

  document.addEventListener('change', function(event){
    if (event.target.matches('.banka-tms-row-check')) {
      updateTaskBulkActions();
      return;
    }
    if (event.target.matches('#banka-tms-check-all')) {
      window.setTimeout(updateTaskBulkActions, 0);
    }
  }, true);

  document.addEventListener('click', function(event){
    if (event.target.closest('#banka-tms-clear-task-filters, #banka-tms-bulk-delete, #banka-tms-bulk-complete')) {
      window.setTimeout(updateTaskBulkActions, 50);
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseTaskMobileControls);
  } else {
    initialiseTaskMobileControls();
  }
  window.addEventListener('pageshow', initialiseTaskMobileControls);
})();




/* v3.0.54 - reliable nested quick-add forms from Add/Edit Task */
(function($){
  'use strict';

  function taskModal(){ return $('#banka-tms-task-modal').first(); }
  function projectModal(){ return $('#banka-tms-project-modal').first(); }

  function markOpenedFromTask($modal, source){
    $modal.data('opened-from-task', true);
    $modal.attr('data-opened-from-task', '1');
    $modal.attr('data-task-source', source || 'task');
  }

  function wasOpenedFromTask($modal){
    return !!($modal.data('opened-from-task') || $modal.attr('data-opened-from-task') === '1');
  }

  function clearOpenedFromTask($modal){
    $modal.removeData('opened-from-task').removeAttr('data-opened-from-task data-task-source');
  }

  function showParentTaskModal(){
    var $task = taskModal();
    if ($task.length) {
      $task.css({display:'flex', visibility:'visible', opacity:1}).show();
      $('body').addClass('banka-tms-modal-open');
    }
  }

  function openNestedModal($modal, source){
    if (!$modal.length) return false;
    if ($modal.parent()[0] !== document.body) $modal.appendTo(document.body);
    markOpenedFromTask($modal, source);
    $modal.addClass('banka-tms-nested-modal').css({
      display:'flex', visibility:'visible', opacity:1, zIndex:100500
    }).show();
    $modal.find('.banka-tms-modal-backdrop').css('z-index',100501);
    $modal.find('.banka-tms-modal-card').css({position:'relative', zIndex:100502});
    $('body').addClass('banka-tms-modal-open');
    return true;
  }

  function closeNestedModal($modal){
    if (!$modal.length) return;
    clearOpenedFromTask($modal);
    $modal.removeClass('banka-tms-nested-modal').hide().css({visibility:'',opacity:'',zIndex:''});
    showParentTaskModal();
  }

  /* Project + button in both Add Task and Edit Task. */
  $(document).off('click.bankaTmsQuickProject', '.banka-tms-quick-add-project');
  $(document).on('click.bankaTmsQuickProject', '.banka-tms-quick-add-project', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    var $modal = projectModal();
    if (!$modal.length) {
      window.alert('Project form could not be loaded. Please refresh the page.');
      return false;
    }
    if (typeof resetProjectForm === 'function') resetProjectForm();
    else {
      $('#project-id').val('0');
      $('#banka-tms-project-form').get(0)?.reset();
      $('#banka-tms-project-modal-title').text('Add New Project');
      $('#banka-tms-project-submit-btn').text('Add New Project');
      $('#banka-tms-project-status').text('');
    }
    openNestedModal($modal, 'project');
    setTimeout(function(){ $('#project-name').trigger('focus'); },80);
    return false;
  });

  /* Cancel/close nested project and return to the exact Task form. */
  $(document).on('click.bankaTmsNestedProjectClose', '#banka-tms-project-modal[data-opened-from-task="1"] [data-close-modal], #banka-tms-project-modal[data-opened-from-task="1"] > .banka-tms-modal-backdrop', function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    closeNestedModal(projectModal());
    return false;
  });

  /* Keep category/task-master nested forms above the Task form and always return. */
  $(document).on('click.bankaTmsNestedCategory', '.banka-tms-quick-add-category', function(){
    var $m=$('#banka-tms-quick-category-modal');
    if($m.length){ markOpenedFromTask($m,'category'); }
  });
  $(document).on('click.bankaTmsNestedTaskMaster', '#banka-tms-quick-add-task-master', function(){
    var $m=$('#banka-tms-quick-task-master-modal');
    if($m.length){ markOpenedFromTask($m,'task-master'); }
  });

  /* Public helpers used by the existing save callbacks. */
  window.bankaTmsTaskNestedModal = {
    isFromTask: wasOpenedFromTask,
    closeToTask: closeNestedModal,
    showTask: showParentTaskModal
  };
})(jQuery);


/* v3.0.56 Meta WhatsApp template help */
(function($){
  function openMetaTemplateHelp(){ $('#banka-tms-meta-template-help-modal').css('display','flex').addClass('is-open'); }
  function closeMetaTemplateHelp(){ $('#banka-tms-meta-template-help-modal').hide().removeClass('is-open'); }
  $(document).on('click','.banka-tms-template-help-btn',function(e){ e.preventDefault(); e.stopPropagation(); openMetaTemplateHelp(); });
  $(document).on('click','[data-meta-template-close],#banka-tms-meta-template-help-modal .banka-tms-modal-backdrop',function(e){ e.preventDefault(); closeMetaTemplateHelp(); });
  $(document).on('click','[data-meta-template-tab]',function(){
    var key=$(this).attr('data-meta-template-tab');
    $('[data-meta-template-tab]').removeClass('is-active'); $(this).addClass('is-active');
    $('[data-meta-template-panel]').removeClass('is-active'); $('[data-meta-template-panel="'+key+'"]').addClass('is-active');
  });
})(jQuery);

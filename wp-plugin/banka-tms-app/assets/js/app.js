jQuery(function($){
  $('.banka-tms-shell').attr('data-ready','true');

  function loadTasks() {
    $.post(bankaTmsApp.ajaxUrl, {
      action: 'banka_tms_app_list_tasks',
      nonce: bankaTmsApp.nonce
    }).done(function(res){
      const list = $('#banka-tms-task-list');
      if (!res.success || !res.data) {
        list.html('<li>No tasks found.</li>');
        return;
      }
      list.empty();
      res.data.forEach(function(task){
        list.append('<li class="banka-tms-row">#' + task.id + ' - ' + task.title + ' [' + task.status + '] <button class="banka-tms-inline-btn" data-action="edit" data-id="' + task.id + '" data-title="' + task.title + '" data-status="' + task.status + '">Edit</button> <button class="banka-tms-inline-btn danger" data-action="delete" data-id="' + task.id + '">Delete</button></li>');
      });
    });
  }

  if ($('#banka-tms-task-form').length) {
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
        status: $('#task-status').val()
      }).done(function(res){
        if (res.success) {
          statusBox.text('Task saved successfully.');
          $('#task-id').val('0');
          $('#task-title').val('');
          $('#task-description').val('');
          $('#task-status').val('Pending');
          loadTasks();
        } else {
          statusBox.text(res.data && res.data.message ? res.data.message : 'Unable to save task');
        }
      });
    });

    $('#banka-tms-task-list').on('click', '[data-action="edit"]', function(){
      $('#task-id').val($(this).data('id'));
      $('#task-title').val($(this).data('title'));
      $('#task-status').val($(this).data('status'));
      $('#task-title').focus();
    });

    $('#banka-tms-task-list').on('click', '[data-action="delete"]', function(){
      const id = $(this).data('id');
      if (!confirm('Delete this task?')) return;
      $.post(bankaTmsApp.ajaxUrl, {
        action: 'banka_tms_app_delete_task',
        nonce: bankaTmsApp.nonce,
        id: id
      }).done(function(res){
        if (res.success) {
          loadTasks();
        }
      });
    });

    loadTasks();
  }

  if ($('#banka-tms-user-form').length) {
    $('#banka-tms-user-form').on('submit', function(e){
      e.preventDefault();
      const statusBox = $('#banka-tms-user-status');
      statusBox.text('Creating user...');

      $.post(bankaTmsApp.ajaxUrl, {
        action: 'banka_tms_app_create_user',
        nonce: bankaTmsApp.nonce,
        name: $('#user-name').val(),
        login: $('#user-login').val(),
        email: $('#user-email').val(),
        password: $('#user-password').val(),
        role: $('#user-role').val()
      }).done(function(res){
        if (res.success) {
          statusBox.text('User created successfully.');
          $('#banka-tms-user-form')[0].reset();
          loadUsers();
        } else {
          statusBox.text(res.data && res.data.message ? res.data.message : 'Unable to create user');
        }
      });
    });
  }

  function loadUsers() {
    $.post(bankaTmsApp.ajaxUrl, {
      action: 'banka_tms_app_list_users',
      nonce: bankaTmsApp.nonce
    }).done(function(res){
      const list = $('#banka-tms-user-list');
      if (!res.success || !res.data) {
        list.html('<p>No users found.</p>');
        return;
      }
      const html = '<ul>' + res.data.map(function(user){
        return '<li class="banka-tms-row"><strong>' + user.name + '</strong> (' + user.email + ') - ' + user.role + '</li>';
      }).join('') + '</ul>';
      list.html(html);
    });
  }

  if ($('#banka-tms-user-list').length) {
    loadUsers();
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
    $.post(bankaTmsApp.ajaxUrl, {
      action: 'banka_tms_app_list_vendors',
      nonce: bankaTmsApp.nonce
    }).done(function(res){
      const list = $('#banka-tms-vendor-list');
      if (!res.success || !res.data) {
        list.html('<p>No vendors found.</p>');
        return;
      }
      const html = '<ul>' + res.data.map(function(vendor){
        return '<li class="banka-tms-row"><strong>' + vendor.name + '</strong> - ' + (vendor.email || 'No email') + ' - ' + (vendor.mobile || 'No mobile') + '</li>';
      }).join('') + '</ul>';
      list.html(html);
    });
  }

  if ($('#banka-tms-vendor-list').length) {
    loadVendors();
  }
});

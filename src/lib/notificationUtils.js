// notificationUtils.js
// Utilities for Telegram notifications, daily reports, pending reports,
// and MAS (WhatsApp) messaging via a Supabase Edge Function.

import { supabase } from './customSupabaseClient';

/* -------------------------------------------------------------------------- */
/*                               Common Utilities                              */
/* -------------------------------------------------------------------------- */

/**
 * Fetch a single row of admin settings.
 * Expects a table `tms_admin_settings`.
 */
async function getSettings() {
  const { data, error } = await supabase
    .from('tms_admin_settings')
    .select('*')
    .limit(1)
    .single();

  if (error) {
    console.error('[notify] Settings fetch failed:', error);
    return null;
  }
  return data;
}

/* -------------------------------------------------------------------------- */
/*                               Telegram Helpers                              */
/* -------------------------------------------------------------------------- */

/**
 * Send a text message to a Telegram chat using an Edge Function.
 * The Edge Function name must be `send-telegram-report`.
 */
export async function sendTelegramMessage(chatId, message) {
  const settings = await getSettings();

  if (!chatId) {
    console.log('[notify] Telegram chatId missing. Skipping.');
    return;
  }
  if (!settings || !settings.telegram?.botToken) {
    console.log('[notify] Telegram is not configured in admin settings. Skipping.');
    return;
  }

  const { error } = await supabase.functions.invoke('send-telegram-report', {
    body: {
      botToken: settings.telegram.botToken,
      chatId,
      reportText: message,
    },
  });

  if (error) {
    console.error('[notify] Telegram send failed:', error);
  } else {
    console.log('[notify] Telegram message sent.');
  }
}

/**
 * Notify a site Telegram group when a task is created or updated.
 * Joins tms_tasks → tms_sites(telegram_group_id).
 */
export async function notifyTaskActivity(taskId, activityType, userName, taskName, remark = '') {
  const { data, error } = await supabase
    .from('tms_tasks')
    .select('site_id, tms_sites(telegram_group_id)')
    .eq('id', taskId)
    .single();

  if (error) {
    console.error('[notify] Site lookup failed:', error);
    return;
  }
  const chatId = data?.tms_sites?.telegram_group_id;
  if (!chatId) return;

  let text = `*Task ${activityType}*\n\n*Task:* ${taskName}\n*By:* ${userName}\n`;
  if (activityType === 'Updated' && remark) {
    text += `*Remark:* ${remark}\n`;
  }
  await sendTelegramMessage(chatId, text);
}

/* -------------------------------------------------------------------------- */
/*                                MAS WhatsApp API                             */
/* -------------------------------------------------------------------------- */

/**
 * Send a WhatsApp message via MAS Edge Function.
 */
export async function sendMASMessage({ clientCode, message, mobiles, groupNumber }) {
  try {
    if (!clientCode || !message) {
      console.warn('[notify] MAS payload missing clientCode or message. Skipping.');
      return;
    }

    const body = {
      clientCode,
      message,
      ...(Array.isArray(mobiles) && mobiles.length ? { mobiles } : {}),
      ...(groupNumber ? { groupNumber } : {}),
    };

    const { data, error } = await supabase.functions.invoke('rapid-worker', {
      body,
    });

    if (error) {
      console.error('[notify] MAS send failed:', error);
      return;
    }
    console.log('[notify] MAS message sent:', data);
    return data;
  } catch (err) {
    console.error('[notify] MAS send error:', err);
  }
}

/* -------------------------------------------------------------------------- */
/*                             Dual-Channel Reports                           */
/* -------------------------------------------------------------------------- */

/**
 * Send an employee-wise activity summary for today to Telegram and WhatsApp.
 */
export async function sendDailyReport() {
  const settings = await getSettings();
  if (!settings) {
    throw new Error('Notification settings are not configured.');
  }

  const today = new Date();
  const startIso = new Date(today.setHours(0, 0, 0, 0)).toISOString();
  const endIso = new Date(today.setHours(23, 59, 59, 999)).toISOString();

  const { data: activities, error } = await supabase
    .from('tms_action_log')
    .select(`
      status, remark,
      user:tms_users!user_id(id, full_name),
      task:tms_tasks(id, task, notes, status,
        task_assignees:tms_task_assignees(user:tms_users(id, full_name))
      )
    `)
    .gte('created_at', startIso)
    .lte('created_at', endIso);

  if (error) throw new Error(`Failed to fetch daily activities: ${error.message}`);
  
  let reportText;
  if (!activities?.length) {
    reportText = `*Daily Activity Report - ${new Date().toLocaleDateString('en-GB')}*\n\nNo activities recorded today.`;
  } else {
    const byEmployee = {};
    for (const a of activities) {
      const assignees = a.task?.task_assignees || [];
      for (const asg of assignees) {
        const emp = asg.user;
        if (!emp) continue;
        if (!byEmployee[emp.id]) byEmployee[emp.id] = { name: emp.full_name, buckets: { 'Not Yet Started': [], 'In Progress': [], 'Completed': [] } };
        const bucket = a.task?.status || 'Not Yet Started';
        byEmployee[emp.id].buckets[bucket]?.push({ name: a.task?.task || '', remark: a.status === 'Created' ? a.task?.notes : a.remark });
      }
    }

    reportText = `*Daily Activity Report - ${new Date().toLocaleDateString('en-GB')}*\n\n`;
    let hasContent = false;
    Object.values(byEmployee).forEach(emp => {
      let section = `*${emp.name}*\n`;
      let any = false;
      for (const [status, list] of Object.entries(emp.buckets)) {
        if (!list.length) continue;
        any = true; hasContent = true;
        section += `_${status}_\n`;
        [...new Map(list.map(t => [t.name, t])).values()].forEach((t, i) => {
          section += `${i + 1}. ${t.name}${t.remark ? ` (${t.remark})` : ''}\n`;
        });
      }
      if (any) reportText += section + '\n';
    });
    if (!hasContent) reportText = `*Daily Activity Report - ${new Date().toLocaleDateString('en-GB')}*\n\nNo relevant activities recorded today.`;
  }

  // Send to Telegram
  if (settings.telegram?.reportingGroupId) await sendTelegramMessage(settings.telegram.reportingGroupId, reportText);
  // Send to WhatsApp
  if (settings.whatsapp_group_id) await sendMASMessage({ clientCode: 'BIZSKILL', message: reportText, groupNumber: settings.whatsapp_group_id });
}

/**
 * Send a pending tasks report by employee to Telegram and WhatsApp.
 */
export async function sendPendingTasksReport() {
  const settings = await getSettings();
  if (!settings) throw new Error('Notification settings not found.');

  const { data: tasks, error } = await supabase.from('tms_tasks').select(`
    task, due_date,
    task_assignees:tms_task_assignees(user:tms_users(id, full_name))
  `).not('status', 'in', '("Completed","Done","Cancelled")');

  if (error) throw new Error(`Failed to fetch pending tasks: ${error.message}`);
  
  let reportText;
  if (!tasks?.length) {
    reportText = `*Pending Tasks Report - ${new Date().toLocaleDateString('en-GB')}*\n\nNo pending tasks today.`;
  } else {
    const byEmployee = {};
    for (const t of tasks) {
      for (const asg of t.task_assignees) {
        const emp = asg.user;
        if (!emp) continue;
        if (!byEmployee[emp.id]) byEmployee[emp.id] = { name: emp.full_name, tasks: [] };
        byEmployee[emp.id].tasks.push({ name: t.task, due: t.due_date });
      }
    }

    reportText = `*Pending Tasks Report - ${new Date().toLocaleDateString('en-GB')}*\n\n`;
    const keys = Object.keys(byEmployee);
    if (!keys.length) {
      reportText += 'No tasks assigned to any employee.';
    } else {
      keys.forEach(k => {
        const emp = byEmployee[k];
        if (!emp.tasks.length) return;
        reportText += `*${emp.name}*\n`;
        emp.tasks.forEach((t, i) => { reportText += `${i + 1}. ${t.name} (Due: ${t.due})\n`; });
        reportText += '\n';
      });
    }
  }

  // Send to Telegram
  if (settings.telegram?.reportingGroupId) await sendTelegramMessage(settings.telegram.reportingGroupId, reportText);
  // Send to WhatsApp
  if (settings.whatsapp_group_id) await sendMASMessage({ clientCode: 'BIZSKILL', message: reportText, groupNumber: settings.whatsapp_group_id });
}


/**
 * Sends each assignee their pending tasks grouped by project via WhatsApp.
 */
export async function sendAssigneePendingTasks() {
  const settings = await getSettings();
  if (!settings || !settings.client_code) {
    console.error('[notify] Assignee reports cannot run without client_code in settings.');
    return;
  }

  const { data: tasks, error } = await supabase
    .from('tms_tasks')
    .select(`
      task, due_date,
      project:tms_sites(id, name, client_name, address),
      assignees:tms_task_assignees(user:tms_users(id, full_name, mobile))
    `)
    .not('status', 'in', '("Completed","Done","Cancelled")');

  if (error) {
    console.error('Error fetching pending tasks for assignees:', error);
    return;
  }

  const tasksByAssignee = {};

  tasks.forEach(task => {
    task.assignees.forEach(({ user }) => {
      if (!user || !user.mobile) return;
      if (!tasksByAssignee[user.id]) {
        tasksByAssignee[user.id] = {
          name: user.full_name,
          mobile: user.mobile,
          projects: {}
        };
      }
      
      const projectLabel = task.project ? `${task.project.client_name || ''} - ${task.project.name || ''}`.replace(/^ - | - $/g, '') : 'Unassigned';
      if (!tasksByAssignee[user.id].projects[projectLabel]) {
        tasksByAssignee[user.id].projects[projectLabel] = [];
      }
      tasksByAssignee[user.id].projects[projectLabel].push({
        name: task.task,
        due: task.due_date
      });
    });
  });

  for (const assigneeId in tasksByAssignee) {
    const assignee = tasksByAssignee[assigneeId];
    let message = `Hi ${assignee.name}, here are your pending tasks for today:\n\n`;
    
    Object.entries(assignee.projects).forEach(([projectName, projectTasks]) => {
      message += `*Project: ${projectName}*\n`;
      projectTasks.forEach((t, i) => {
        const dueDate = t.due ? new Date(t.due).toLocaleDateString('en-GB') : 'N/A';
        message += `${i + 1}. ${t.name} (Due: ${dueDate})\n`;
      });
      message += '\n';
    });

    await sendMASMessage({
      clientCode: settings.client_code,
      message,
      mobiles: [assignee.mobile]
    });
  }
}
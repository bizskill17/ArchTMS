import { supabase } from './customSupabaseClient';
import { 
  sendDailyReport as sendDailyReportUtil, 
  sendPendingTasksReport as sendPendingTasksReportUtil,
  sendAssigneePendingTasks as sendAssigneePendingTasksUtil
} from './notificationUtils';

let reportTimeoutId = null;
let pendingReportTimeoutId = null;
let assigneeReportTimeoutId = null;

const scheduleReport = (config) => {
  const { timeoutId, settings, triggerTimePath, enabledPath, logName, sendFunction } = config;

  if (timeoutId) {
    clearTimeout(timeoutId);
    config.timeoutId = null;
  }
  
  const triggerTime = triggerTimePath.split('.').reduce((o, i) => o?.[i], settings);
  const isEnabled = enabledPath.split('.').reduce((o, i) => o?.[i], settings);

  if (!triggerTime || !isEnabled) {
    console.log(`Automated ${logName} is disabled or time not set.`);
    return null;
  }

  const [hours, minutes] = triggerTime.split(':').map(Number);
  
  const now = new Date();
  let nextTrigger = new Date();
  nextTrigger.setHours(hours, minutes, 0, 0);

  if (now > nextTrigger) {
    nextTrigger.setDate(nextTrigger.getDate() + 1);
  }

  const delay = nextTrigger.getTime() - now.getTime();
  
  console.log(`Next ${logName} scheduled for: ${nextTrigger.toLocaleString()}. Will trigger in ${Math.round(delay / 60000)} minutes.`);

  return setTimeout(async () => {
    try {
      console.log(`Triggering automated ${logName}...`);
      await sendFunction();
      console.log(`Automated ${logName} sent successfully.`);
    } catch (e) {
      console.error(`Failed to send automated ${logName}:`, e);
    }
    // Reschedule for the next day
    scheduleReport(config);
  }, delay);
};


const scheduleAssigneeReport = (settings) => {
  if (assigneeReportTimeoutId) {
    clearTimeout(assigneeReportTimeoutId);
    assigneeReportTimeoutId = null;
  }

  // Use a hardcoded time of 11:00 AM as requested
  const triggerTime = "11:00"; 
  const isEnabled = settings?.telegram?.sendDailyReport; // Use a general flag to enable/disable all scheduled reports

  if (!isEnabled) {
      console.log("Automated assignee pending tasks report is disabled.");
      return;
  }

  const [hours, minutes] = triggerTime.split(':').map(Number);
  const now = new Date();
  let nextTrigger = new Date();
  nextTrigger.setHours(hours, minutes, 0, 0);

  if (now > nextTrigger) {
      nextTrigger.setDate(nextTrigger.getDate() + 1);
  }

  const delay = nextTrigger.getTime() - now.getTime();
  console.log(`Next assignee pending tasks report scheduled for: ${nextTrigger.toLocaleString()}.`);

  assigneeReportTimeoutId = setTimeout(async () => {
      try {
          console.log("Triggering automated assignee pending tasks report...");
          await sendAssigneePendingTasksUtil();
          console.log("Automated assignee reports sent successfully.");
      } catch (e) {
          console.error("Failed to send automated assignee reports:", e);
      }
      scheduleAssigneeReport(settings);
  }, delay);
};


export const initializeScheduler = async () => {
  const { data: settings, error } = await supabase.from('tms_admin_settings').select('*').limit(1).single();
  if (error || !settings) {
    console.error("Could not fetch settings to initialize schedulers.");
    return;
  }
  
  reportTimeoutId = scheduleReport({
    timeoutId: reportTimeoutId,
    settings,
    triggerTimePath: 'report_triggers.dailyActivityTime',
    enabledPath: 'telegram.sendDailyReport',
    logName: 'daily activity report',
    sendFunction: sendDailyReportUtil,
  });

  pendingReportTimeoutId = scheduleReport({
    timeoutId: pendingReportTimeoutId,
    settings,
    triggerTimePath: 'report_triggers.pendingTasksTime',
    enabledPath: 'telegram.sendDailyReport',
    logName: 'pending tasks report',
    sendFunction: sendPendingTasksReportUtil,
  });

  scheduleAssigneeReport(settings);
};


export const processRecurringTasks = async () => {
  const { data: recurringTasks, error } = await supabase.from('tms_recurring_tasks').select('*');
  if (error) {
    console.error("Error fetching recurring tasks:", error);
    return;
  }

  const today = new Date();
  const dayOfWeek = today.getDay(); // 0=Sun, 1=Mon, ...
  const dayOfMonth = today.getDate();
  const month = today.getMonth() + 1; // 1-12

  for (const task of recurringTasks) {
    let shouldCreate = false;
    switch (task.type) {
      case 'daily':
        shouldCreate = true;
        break;
      case 'weekly':
        if (task.config_json.day == dayOfWeek) shouldCreate = true;
        break;
      case 'monthly':
        if (task.config_json.monthDay == dayOfMonth) shouldCreate = true;
        break;
      case 'yearly':
        if (task.config_json.dueMonth == month && task.config_json.dueDay == dayOfMonth) shouldCreate = true;
        break;
      default:
        break;
    }

    if (shouldCreate) {
      const dueDate = new Date();
      dueDate.setDate(dueDate.getDate() + 1); // Due tomorrow
      const dueDateString = dueDate.toISOString().split('T')[0];

      const { data: existing } = await supabase.from('tms_tasks')
        .select('id')
        .eq('recurring_source_id', task.id)
        .eq('due_date', dueDateString);

      if (!existing || existing.length === 0) {
        const taskDetails = task.task_details_json;
        const { data: newTask, error: creationError } = await supabase.from('tms_tasks').insert({
          task: taskDetails.task,
          category_id: taskDetails.categoryId,
          site_id: taskDetails.siteId,
          priority: 'Medium',
          status: 'Not Yet Started',
          due_date: dueDateString,
          recurring_source_id: task.id,
        }).select().single();

        if (!creationError && newTask) {
          const assigneesToInsert = taskDetails.assigneeIds.map(userId => ({ task_id: newTask.id, user_id: userId }));
          await supabase.from('tms_task_assignees').insert(assigneesToInsert);

          const ownersToInsert = taskDetails.taskOwnerIds.map(userId => ({ task_id: newTask.id, user_id: userId }));
          await supabase.from('tms_task_owners').insert(ownersToInsert);
        }
      }
    }
  }
};
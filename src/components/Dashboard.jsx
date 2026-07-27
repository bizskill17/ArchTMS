import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { CheckSquare, Clock, AlertTriangle, CheckCircle, Users, Building2, Plus, FileDown, UserCheck, Send } from 'lucide-react';
import { useToast } from '@/components/ui/use-toast';
import { exportToPDF, exportToExcel } from '@/lib/exportUtils';
import { Button } from '@/components/ui/button';
import { supabase } from '@/lib/customSupabaseClient';
import { sendDailyReport } from '@/lib/notificationUtils';

const formatProjectLabel = (project) => {
  if (!project) return 'Unassigned';
  const parts = [project.client_name, project.name].filter(Boolean);
  if (parts.length === 0 && project.address) return project.address;
  let mainPart = parts.join(' - ');
  if (project.address) mainPart += ` (${project.address})`;
  return mainPart || 'Unnamed Project';
};

const Dashboard = ({ setActiveTab, currentUser }) => {
  const [stats, setStats] = useState({
    totalTasks: 0, pendingTasks: 0, overdueTasks: 0, completedTasks: 0, totalUsers: 0
  });
  const [assigneeAnalysis, setAssigneeAnalysis] = useState([]);
  const [projectAnalysis, setProjectAnalysis] = useState([]);
  const [isSendingReport, setIsSendingReport] = useState(false);
  const { toast } = useToast();

  const loadDashboardData = useCallback(async () => {
    try {
      let tasksQuery = supabase.from('tms_tasks').select(`
        id, status, due_date, 
        task_assignees:tms_task_assignees(user:tms_users(id, full_name)),
        project:tms_sites(id, name, client_name, address)
      `);

      if (currentUser.role === 'Employee') {
        const { data: taskIds, error: idError } = await supabase.rpc('get_employee_tasks');
        if (idError) throw idError;
        if (taskIds && taskIds.length > 0) {
          tasksQuery = tasksQuery.in('id', taskIds.map(t => t.task_id));
        } else {
          setStats(prev => ({ ...prev, totalTasks: 0, pendingTasks: 0, overdueTasks: 0, completedTasks: 0 }));
          setAssigneeAnalysis([]);
          setProjectAnalysis([]);
          return;
        }
      }

      const { data: tasks, error: tasksError } = await tasksQuery;
      if (tasksError) throw tasksError;
      
      const { count: usersCount, error: usersError } = await supabase.from('tms_users').select('*', { count: 'exact', head: true });
      if (usersError) throw usersError;
      
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const terminalStatuses = new Set(['Completed', 'Done', 'Cancelled']);
      
      setStats({
        totalTasks: tasks.length,
        pendingTasks: tasks.filter(t => !terminalStatuses.has(t.status)).length,
        overdueTasks: tasks.filter(t => new Date(t.due_date) < today && !terminalStatuses.has(t.status)).length,
        completedTasks: tasks.filter(t => t.status === 'Completed').length,
        totalUsers: usersCount,
      });

      const { data: usersData, error: usersDataError } = await supabase.from('tms_users').select('id, full_name');
      if (usersDataError) throw usersDataError;

      // Assignee-wise analysis
      const newAssigneeAnalysis = usersData.map(user => {
        const userTasks = tasks.filter(task => task.task_assignees.some(a => a.user.id === user.id) && !terminalStatuses.has(task.status));
        return {
          employeeId: user.id,
          employee: user.full_name,
          totalPending: userTasks.length,
          notYetStarted: userTasks.filter(t => t.status === 'Not Yet Started').length,
          inProgress: userTasks.filter(t => t.status === 'In Progress').length
        };
      }).filter(u => u.totalPending > 0);
      setAssigneeAnalysis(newAssigneeAnalysis);
      
      // Project-wise analysis
      const projects = [...new Map(tasks.filter(t => t.project).map(t => [t.project.id, t.project])).values()];
      const newProjectAnalysis = projects.map(project => {
          const projectTasks = tasks.filter(t => t.project?.id === project.id && !terminalStatuses.has(t.status));
          return {
              projectId: project.id,
              project: formatProjectLabel(project),
              totalPending: projectTasks.length,
              notYetStarted: projectTasks.filter(t => t.status === 'Not Yet Started').length,
              inProgress: projectTasks.filter(t => t.status === 'In Progress').length,
          };
      }).filter(p => p.totalPending > 0);
      setProjectAnalysis(newProjectAnalysis);

    } catch (error) {
      toast({ title: "Error loading dashboard", description: error.message, variant: "destructive" });
    }
  }, [toast, currentUser]);

  useEffect(() => { loadDashboardData(); }, [loadDashboardData]);

  const handleQuickAction = (tab, actionType, filter = {}) => {
    const params = new URLSearchParams();
    params.set('tab', tab);
    params.set('action', actionType);
    params.set('filter', encodeURIComponent(JSON.stringify(filter)));
    window.history.pushState({}, '', `/?${params.toString()}`);
    window.dispatchEvent(new PopStateEvent('popstate'));
  };

  const handleDownload = format => {
    const headers = ["Statistic", "Value"];
    const dataToExport = [
      ["Total Tasks", stats.totalTasks], ["Pending Tasks", stats.pendingTasks],
      ["Overdue Tasks", stats.overdueTasks], ["Completed Tasks", stats.completedTasks],
      ["Total Users", stats.totalUsers],
    ];
    if (format === 'Excel') {
      exportToExcel(dataToExport, headers, 'Dashboard_Report');
    } else {
      exportToPDF(dataToExport, headers, 'Dashboard Report');
    }
    toast({ title: "Success", description: `Dashboard report downloaded as ${format}.` });
  };
  
  const handleSendReport = async () => {
    setIsSendingReport(true);
    try {
      await sendDailyReport();
      toast({ title: "Report Sent", description: "The daily activity report has been sent successfully." });
    } catch (error) {
      toast({ title: "Failed to Send Report", description: error.message, variant: "destructive" });
    } finally {
      setIsSendingReport(false);
    }
  };

  const statCards = [
    { title: 'Total Tasks', value: stats.totalTasks, icon: CheckSquare, color: 'from-blue-500 to-blue-600', bgColor: 'bg-blue-50' },
    { title: 'Pending Tasks', value: stats.pendingTasks, icon: Clock, color: 'from-orange-500 to-orange-600', bgColor: 'bg-orange-50' },
    { title: 'Overdue Tasks', value: stats.overdueTasks, icon: AlertTriangle, color: 'from-red-500 to-red-600', bgColor: 'bg-red-50' },
    { title: 'Completed Tasks', value: stats.completedTasks, icon: CheckCircle, color: 'from-green-500 to-green-600', bgColor: 'bg-green-50' },
    { title: 'Total Users', value: stats.totalUsers, icon: Users, color: 'from-purple-500 to-purple-600', bgColor: 'bg-purple-50' },
  ];

  return <div className="space-y-8">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="text-center">
        <h1 className="text-4xl font-bold gradient-text mb-4">Dashboard</h1>
      </motion.div>

      <motion.div initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.3 }} className="task-card">
        <h3 className="text-xl font-semibold mb-4 gradient-text">Quick Actions</h3>
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
          <Button onClick={() => handleQuickAction('tasks', 'new-task')} className="w-full justify-center bg-gradient-to-r from-blue-500 to-purple-600 text-white"><Plus className="w-4 h-4 mr-2" /> New Task</Button>
          <Button onClick={() => handleQuickAction('users', 'new-user')} className="w-full justify-center bg-gradient-to-r from-green-500 to-teal-600 text-white"><Users className="w-4 h-4 mr-2" /> Add User</Button>
          <Button onClick={() => handleDownload('PDF')} className="w-full justify-center bg-gradient-to-r from-orange-500 to-red-600 text-white"><FileDown className="w-4 h-4 mr-2" /> PDF</Button>
          <Button onClick={() => handleDownload('Excel')} className="w-full justify-center bg-gradient-to-r from-yellow-500 to-lime-600 text-white"><FileDown className="w-4 h-4 mr-2" /> Excel</Button>
          <Button onClick={handleSendReport} disabled={isSendingReport} className="w-full justify-center bg-gradient-to-r from-pink-500 to-rose-500 text-white">
            {isSendingReport ? 'Sending...' : <><Send className="w-4 h-4 mr-2" /> Daily Report</>}
          </Button>
        </div>
      </motion.div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        {statCards.map((card, index) => {
          const Icon = card.icon;
          return <motion.div key={card.title} initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: index * 0.1 }} className={`task-card ${card.bgColor} hover:shadow-2xl`}>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-600 mb-1">{card.title}</p>
                  <p className="text-3xl font-bold text-gray-900">{card.value}</p>
                </div>
                <div className={`p-3 rounded-full bg-gradient-to-r ${card.color}`}><Icon className="w-6 h-6 text-white" /></div>
              </div>
            </motion.div>;
        })}
      </div>

      <motion.div initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.4 }} className="task-card">
        <h3 className="text-xl font-semibold mb-4 gradient-text">Assigneewise Pending Tasks</h3>
        <div className="hidden md:block overflow-x-auto">
          <table className="w-full">
            <thead><tr className="border-b"><th className="p-2 text-left font-semibold">Employee</th><th className="p-2 text-left font-semibold">Total Pending</th><th className="p-2 text-left font-semibold">Not Yet Started</th><th className="p-2 text-left font-semibold">In Progress</th></tr></thead>
            <tbody>
              {assigneeAnalysis.map((item) => <tr key={item.employeeId} className="border-b hover:bg-gray-50 cursor-pointer" onClick={() => handleQuickAction('pending-tasks', 'filter-assignee', { assignee: item.employeeId })}>
                  <td className="p-2 font-medium">{item.employee}</td><td className="p-2">{item.totalPending}</td><td className="p-2">{item.notYetStarted}</td><td className="p-2">{item.inProgress}</td>
                </tr>)}
            </tbody>
          </table>
          {assigneeAnalysis.length === 0 && <p className="text-center text-gray-500 py-4">No pending tasks to analyze.</p>}
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.5 }} className="task-card">
        <h3 className="text-xl font-semibold mb-4 gradient-text">Projectwise Pending Tasks</h3>
        <div className="hidden md:block overflow-x-auto">
          <table className="w-full">
            <thead><tr className="border-b"><th className="p-2 text-left font-semibold">Project</th><th className="p-2 text-left font-semibold">Total Pending</th><th className="p-2 text-left font-semibold">Not Yet Started</th><th className="p-2 text-left font-semibold">In Progress</th></tr></thead>
            <tbody>
              {projectAnalysis.map((item) => <tr key={item.projectId} className="border-b hover:bg-gray-50 cursor-pointer" onClick={() => handleQuickAction('pending-tasks', 'filter-project', { site: item.projectId })}>
                  <td className="p-2 font-medium">{item.project}</td><td className="p-2">{item.totalPending}</td><td className="p-2">{item.notYetStarted}</td><td className="p-2">{item.inProgress}</td>
                </tr>)}
            </tbody>
          </table>
          {projectAnalysis.length === 0 && <p className="text-center text-gray-500 py-4">No pending tasks to analyze.</p>}
        </div>
      </motion.div>
    </div>;
};
export default Dashboard;
import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Search, FileText, User, Calendar as CalendarIcon, FileDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Calendar } from "@/components/ui/calendar";
import { format } from "date-fns";
import { cn } from "@/lib/utils";
import { exportToPDF, exportToExcel } from '@/lib/exportUtils';
import { supabase } from '@/lib/customSupabaseClient';
import { useToast } from '@/components/ui/use-toast';
import { Combobox } from '@/components/ui/combobox';

const formatProjectLabel = (project) => {
  if (!project) return 'Unassigned';
  const parts = [project.client_name, project.name].filter(Boolean);
  if (parts.length === 0 && project.address) return project.address;
  let mainPart = parts.join(' - ');
  if (project.address) mainPart += ` (${project.address})`;
  return mainPart || 'Unnamed Project';
};


const ActionLog = ({ currentUser }) => {
  const [actionLog, setActionLog] = useState([]);
  const [filteredLog, setFilteredLog] = useState([]);
  const [filters, setFilters] = useState({
    searchTerm: '',
    updateStatus: 'all',
    assignee: 'all',
    owner: 'all',
    site: 'all',
    fromDate: null,
    toDate: null,
  });
  const [users, setUsers] = useState([]);
  const [sites, setSites] = useState([]);
  const { toast } = useToast();

  const loadData = useCallback(async () => {
    const { data: logData, error: logError } = await supabase
      .from('tms_action_log')
      .select(`
        *,
        task:tms_tasks!inner(
          task,
          site:tms_sites(id, name, client_name, address),
          task_owners:tms_task_owners(user:tms_users(id, full_name)),
          task_assignees:tms_task_assignees(user:tms_users(id, full_name))
        ),
        user:tms_users!user_id(full_name),
        reassigned_user:tms_users!reassigned_to_user_id(full_name)
      `)
      .order('created_at', { ascending: false });

    if (logError) {
      toast({ title: "Error loading action log", description: logError.message, variant: "destructive" });
      return;
    }

    const formattedLogs = logData.map(log => ({
      ...log,
      taskName: log.task?.task,
      userName: log.user?.full_name,
      reassignedToName: log.reassigned_user?.full_name,
      owners: log.task?.task_owners?.map(o => o.user.full_name).join(', '),
      assignees: log.task?.task_assignees?.map(a => a.user.full_name).join(', '),
      siteName: log.task?.site ? formatProjectLabel(log.task.site) : 'Unassigned',
    }));
    setActionLog(formattedLogs);

    const { data: usersData, error: usersError } = await supabase.from('tms_users').select('id, full_name').order('full_name');
    if (!usersError) setUsers(usersData.map(u => ({ value: u.id, label: u.full_name })));

    const { data: sitesData, error: sitesError } = await supabase.from('tms_sites').select('id, name, client_name, address').order('name');
    if (!sitesError) setSites(sitesData.map(s => ({ value: s.id, label: formatProjectLabel(s) })));
  }, [toast]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const filterLogs = useCallback(() => {
    let logs = [...actionLog];
    
    if (filters.searchTerm) {
      logs = logs.filter(log =>
        (log.taskName && log.taskName.toLowerCase().includes(filters.searchTerm.toLowerCase())) ||
        (log.status && log.status.toLowerCase().includes(filters.searchTerm.toLowerCase())) ||
        (log.remark && log.remark.toLowerCase().includes(filters.searchTerm.toLowerCase()))
      );
    }
    if (filters.updateStatus !== 'all') logs = logs.filter(log => log.status === filters.updateStatus);
    if (filters.fromDate) logs = logs.filter(log => new Date(log.created_at) >= filters.fromDate);
    if (filters.toDate) logs = logs.filter(log => new Date(log.created_at) <= filters.toDate);

    setFilteredLog(logs);
  }, [actionLog, filters]);

  useEffect(() => {
    filterLogs();
  }, [filterLogs]);

  const handleFilterChange = (filterName, value) => {
    setFilters(prev => ({ ...prev, [filterName]: value }));
  };

  const handleDownload = (formatType) => {
    const headers = ["Task", "Update Date", "Status", "Remarks", "Updated By", "Owner(s)", "Assignee(s)", "Project"];
    const data = filteredLog.map(log => [
      log.taskName,
      format(new Date(log.created_at), "yyyy-MM-dd HH:mm"),
      log.status,
      log.remark,
      log.userName,
      log.owners,
      log.assignees,
      log.siteName || ''
    ]);
    const title = `Action_Log_Report`;
    if (formatType === 'Excel') {
      exportToExcel(data, headers, title);
    } else {
      exportToPDF(data, headers, title.replace(/_/g, ' '));
    }
  };

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="flex flex-col md:flex-row justify-between md:items-center gap-4">
        <div className="w-full md:w-auto">
          <h1 className="text-3xl font-bold gradient-text">Action Log</h1>
          <p className="text-gray-600">Complete history of all task activities</p>
        </div>
        <div className="flex gap-2 w-full md:w-auto">
          <Button onClick={() => handleDownload('Excel')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> Excel</Button>
          <Button onClick={() => handleDownload('PDF')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> PDF</Button>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 mb-6">
          <div className="relative sm:col-span-full">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input type="text" placeholder="Search logs..." value={filters.searchTerm} onChange={(e) => handleFilterChange('searchTerm', e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" />
          </div>
          <select value={filters.updateStatus} onChange={(e) => handleFilterChange('updateStatus', e.target.value)} className="w-full px-3 py-2 border rounded-lg"><option value="all">All Actions</option><option>Created</option><option>Updated</option><option>Status Update</option><option>Completed</option><option>Reassigned</option><option>Deleted</option></select>
          <Combobox options={[{value: 'all', label: 'All Assignees'}, ...users]} value={filters.assignee} onChange={(val) => handleFilterChange('assignee', val)} placeholder="Select Assignee..."/>
          <Combobox options={[{value: 'all', label: 'All Owners'}, ...users]} value={filters.owner} onChange={(val) => handleFilterChange('owner', val)} placeholder="Select Owner..."/>
          <Combobox options={[{value: 'all', label: 'All Projects'}, ...sites]} value={filters.site} onChange={(val) => handleFilterChange('site', val)} placeholder="Select Project..."/>
          <Popover><PopoverTrigger asChild><Button variant={"outline"} className={cn("w-full justify-start text-left font-normal", !filters.fromDate && "text-muted-foreground")}><CalendarIcon className="mr-2 h-4 w-4" />{filters.fromDate ? format(filters.fromDate, "PPP") : <span>From Date</span>}</Button></PopoverTrigger><PopoverContent className="w-auto p-0"><Calendar mode="single" selected={filters.fromDate} onSelect={(date) => handleFilterChange('fromDate', date)} initialFocus /></PopoverContent></Popover>
          <Popover><PopoverTrigger asChild><Button variant={"outline"} className={cn("w-full justify-start text-left font-normal", !filters.toDate && "text-muted-foreground")}><CalendarIcon className="mr-2 h-4 w-4" />{filters.toDate ? format(filters.toDate, "PPP") : <span>To Date</span>}</Button></PopoverTrigger><PopoverContent className="w-auto p-0"><Calendar mode="single" selected={filters.toDate} onSelect={(date) => handleFilterChange('toDate', date)} initialFocus /></PopoverContent></Popover>
        </div>

        <div className="hidden md:block overflow-x-auto">
          <table className="w-full">
            <thead><tr className="border-b"><th className="p-3 text-left">Task</th><th className="p-3 text-left">Update Date</th><th className="p-3 text-left">Status</th><th className="p-3 text-left">Remarks</th><th className="p-3 text-left">Owner(s)</th><th className="p-3 text-left">Assignee(s)</th><th className="p-3 text-left">Project</th></tr></thead>
            <tbody>
              {filteredLog.map((log) => (
                <motion.tr key={log.id} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="border-b hover:bg-gray-50/50">
                  <td className="p-3 font-medium">{log.taskName}</td>
                  <td className="p-3">{format(new Date(log.created_at), "yyyy-MM-dd HH:mm")}</td>
                  <td className="p-3">{log.status}</td>
                  <td className="p-3 text-sm">{log.remark}</td>
                  <td className="p-3">{log.owners}</td>
                  <td className="p-3">{log.assignees}</td>
                  <td className="p-3">{log.siteName}</td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="md:hidden grid grid-cols-1 gap-4">
          {filteredLog.map((log) => (
            <div key={log.id} className="p-4 rounded-lg shadow-md space-y-2 border">
              <p className="font-bold">{log.taskName}</p>
              <p className="text-sm">Status: <span className="font-semibold">{log.status}</span> on {format(new Date(log.created_at), "PP")}</p>
              <p className="text-sm">Remark: {log.remark}</p>
              <p className="text-sm">Owner(s): {log.owners}</p>
              <p className="text-sm">Assignee(s): {log.assignees}</p>
              <p className="text-sm">Project: {log.siteName}</p>
            </div>
          ))}
        </div>
        
        {filteredLog.length === 0 && <div className="text-center py-8"><FileText className="w-12 h-12 text-gray-300 mx-auto mb-4" /><p className="text-gray-500">No action logs found</p></div>}
      </motion.div>
    </div>
  );
};

export default ActionLog;
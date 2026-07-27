import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { FileDown, Search, Calendar as CalendarIcon, Send, Plus, PenLine as FilePenLine, Trash2, CheckCircle, Info, RefreshCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from "@/components/ui/checkbox"
import { exportToPDF, exportToExcel, exportGroupedToPDF } from '@/lib/exportUtils';
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Calendar } from "@/components/ui/calendar";
import { format } from "date-fns";
import { cn } from "@/lib/utils";
import { useToast } from '@/components/ui/use-toast';
import UpdateTaskModal from '@/components/UpdateTaskModal';
import TaskForm from '@/components/TaskForm';
import TaskDetails from '@/components/TaskDetails';
import { supabase } from '@/lib/customSupabaseClient';
import { sendPendingTasksReport } from '@/lib/notificationUtils';
import { Combobox } from '@/components/ui/combobox';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"

const formatProjectLabel = (project) => {
  if (!project) return 'Unassigned';
  const parts = [project.client_name, project.name].filter(Boolean);
  if (parts.length === 0 && project.address) return project.address;
  let mainPart = parts.join(' - ');
  if (project.address) mainPart += ` (${project.address})`;
  return mainPart || 'Unnamed Project';
};

const PendingTasks = ({ currentUser, initialFilters }) => {
  const [tasks, setTasks] = useState([]);
  const [filteredTasks, setFilteredTasks] = useState([]);
  const [users, setUsers] = useState([]);
  const [sites, setSites] = useState([]);
  const [filters, setFilters] = useState({
    searchTerm: '',
    owner: 'all',
    assignee: 'all',
    site: 'all',
    status: 'all',
    fromDate: null,
    toDate: null,
  });
  const [selectedTask, setSelectedTask] = useState(null);
  const [isUpdating, setIsUpdating] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isViewing, setIsViewing] = useState(false);
  const [isCreating, setIsCreating] = useState(false);
  const [isSendingReport, setIsSendingReport] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [selectedRows, setSelectedRows] = useState([]);
  const [reassignTo, setReassignTo] = useState('');
  const [isReassignModalOpen, setIsReassignModalOpen] = useState(false);
  const { toast } = useToast();

  useEffect(() => {
    if (initialFilters) {
      setFilters(prev => ({ ...prev, ...initialFilters }));
    }
  }, [initialFilters]);

  const loadData = useCallback(async () => {
    setIsLoading(true);
    let query = supabase.from('tms_tasks').select(`
      *,
      site:tms_sites(id, name, client_name, address),
      task_assignees:tms_task_assignees(user:tms_users(id, full_name)),
      task_owners:tms_task_owners(user:tms_users(id, full_name))
    `).not('status', 'in', '("Completed","Done","Cancelled")');

    const { data, error } = await query;
    if (error) {
      toast({ title: "Error loading tasks", description: error.message, variant: "destructive" });
      setTasks([]);
    } else {
      const formattedTasks = data.map(t => ({
        ...t,
        siteName: t.site ? formatProjectLabel(t.site) : 'Unassigned',
        assignees: t.task_assignees.map(a => a.user.full_name),
        assigneeIds: t.task_assignees.map(a => a.user.id),
        taskOwners: t.task_owners.map(o => o.user.full_name),
        taskOwnerIds: t.task_owners.map(o => o.user.id),
      }));
      setTasks(formattedTasks);
    }
    setIsLoading(false);
  }, [toast]);

  useEffect(() => {
    loadData();
    const loadDropdownData = async () => {
      const { data: usersData } = await supabase.from('tms_users').select('id, full_name').order('full_name');
      setUsers(usersData?.map(u => ({ value: u.id, label: u.full_name })) || []);
      const { data: sitesData } = await supabase.from('tms_sites').select('id, name, client_name, address').order('name');
      setSites(sitesData?.map(s => ({ value: s.id, label: formatProjectLabel(s) })) || []);
    };
    loadDropdownData();
  }, [loadData]);
  
  useEffect(() => {
    let filtered = [...tasks];
    if (filters.searchTerm) filtered = filtered.filter(t => t.task.toLowerCase().includes(filters.searchTerm.toLowerCase()) || (t.last_update_remark && t.last_update_remark.toLowerCase().includes(filters.searchTerm.toLowerCase())));
    if (filters.owner !== 'all') filtered = filtered.filter(t => (t.taskOwnerIds ?? []).map(String).includes(String(filters.owner)));
    if (filters.assignee !== 'all') filtered = filtered.filter(t => (t.assigneeIds ?? []).map(String).includes(String(filters.assignee)));
    if (filters.site !== 'all') filtered = filtered.filter(t => String(t.site_id) === String(filters.site));
    if (filters.status !== 'all') filtered = filtered.filter(t => t.status === filters.status);
    if (filters.fromDate) filtered = filtered.filter(t => t.due_date && new Date(t.due_date) >= filters.fromDate);
    if (filters.toDate) filtered = filtered.filter(t => t.due_date && new Date(t.due_date) <= filters.toDate);
    setFilteredTasks(filtered);
  }, [tasks, filters]);

  const handleFilterChange = (filterName, value) => {
    setFilters(prev => ({ ...prev, [filterName]: value }));
  };
  
  const handleSaveTask = async () => {
     setIsCreating(false);
     setIsEditing(false);
     setSelectedTask(null);
     loadData();
  };
  
  const handleSendReport = async () => {
    setIsSendingReport(true);
    try {
      await sendPendingTasksReport();
      toast({ title: "Report Sent", description: "The pending tasks report has been sent successfully." });
    } catch (error) {
      toast({ title: "Failed to Send Report", description: error.message, variant: "destructive" });
    } finally {
      setIsSendingReport(false);
    }
  };

  const handleDownload = (format, groupBy = null) => {
    const title = 'Pending Tasks Report';
    const headers = ["S.No.", "Task", "Assignees", "Owner", "Project", "Status", "Remark", "Due Date"];
    const dataToExport = filteredTasks;

    if (groupBy) {
        exportGroupedToPDF(dataToExport, headers, title, groupBy, (task, index) => [index + 1, task.task, task.assignees.join(', '), task.taskOwners.join(', '), task.siteName, task.status, task.last_update_remark || '', task.due_date]);
    } else {
        const data = dataToExport.map((task, index) => [
            index + 1, task.task, task.assignees.join(', '), task.taskOwners.join(', '),
            task.siteName, task.status, task.last_update_remark || '', task.due_date
        ]);

        if (format === 'Excel') {
            exportToExcel(data, headers, title.replace(/ /g, '_'));
        } else {
            exportToPDF(data, headers, title);
        }
    }
  };

  const handleBulkDelete = async () => {
    const { error } = await supabase.from('tms_tasks').delete().in('id', selectedRows);
    if (error) { toast({ title: "Error deleting tasks", description: error.message, variant: "destructive" }); }
    else { toast({ title: "Success", description: "Selected tasks deleted." }); loadData(); setSelectedRows([]); }
  };
  
  const handleBulkComplete = async () => {
    const { error } = await supabase.from('tms_tasks').update({ status: 'Completed', last_update_remark: 'Bulk completed' }).in('id', selectedRows);
    if (error) { toast({ title: "Error completing tasks", description: error.message, variant: "destructive" }); }
    else { toast({ title: "Success", description: "Selected tasks marked as completed." }); loadData(); setSelectedRows([]); }
  };

  const handleBulkReassign = async () => {
    if (!reassignTo) { toast({ title: "No user selected", variant: "destructive" }); return; }
    
    for (const taskId of selectedRows) {
        await supabase.from('tms_task_assignees').delete().eq('task_id', taskId);
        await supabase.from('tms_task_assignees').insert({ task_id: taskId, user_id: reassignTo });
    }
    toast({ title: "Success", description: "Tasks reassigned." });
    setIsReassignModalOpen(false);
    setReassignTo('');
    setSelectedRows([]);
    loadData();
  };

  const canEdit = (task) => currentUser.role === 'Admin' || currentUser.role === 'Developer' || task.taskOwnerIds.includes(currentUser.id);
  const getStatusClass = (status) => ({ 'Not Yet Started': 'status-not-started', 'In Progress': 'status-progress', 'Overdue': 'status-overdue' }[status] || 'status-pending');

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-3xl font-bold gradient-text">Pending Tasks</h1>
          <p className="text-gray-600">Detailed view of all non-completed tasks</p>
        </div>
        <div className="flex gap-2">
          {currentUser.role !== 'Employee' && <Button onClick={() => setIsCreating(true)}><Plus className="w-4 h-4 mr-2"/> Add Task</Button>}
          <Button onClick={handleSendReport} disabled={isSendingReport} variant="outline" className="bg-blue-100 hover:bg-blue-200">
            {isSendingReport ? 'Sending...' : <><Send className="w-4 h-4 mr-2" /> Telegram Report</>}
          </Button>
          <Button onClick={() => handleDownload('Excel')} variant="outline"><FileDown className="w-4 h-4 mr-2" /> Excel</Button>
          <Popover>
            <PopoverTrigger asChild><Button variant="outline"><FileDown className="w-4 h-4 mr-2" /> PDF</Button></PopoverTrigger>
            <PopoverContent className="w-56">
                <div className="grid gap-4">
                    <Button variant="ghost" onClick={() => handleDownload('PDF')}>Standard PDF</Button>
                    <Button variant="ghost" onClick={() => handleDownload('PDF', 'site')}>Group by Project</Button>
                    <Button variant="ghost" onClick={() => handleDownload('PDF', 'assignee')}>Group by Assignee</Button>
                    <Button variant="ghost" onClick={() => handleDownload('PDF', 'owner')}>Group by Owner</Button>
                </div>
            </PopoverContent>
          </Popover>
        </div>
      </motion.div>

      {selectedRows.length > 0 && (
        <motion.div initial={{ y: -20, opacity: 0 }} animate={{ y: 0, opacity: 1 }} className="flex items-center gap-4 p-4 bg-gray-100 rounded-lg">
          <p className="text-sm font-semibold">{selectedRows.length} task(s) selected</p>
          <AlertDialog>
            <AlertDialogTrigger asChild><Button variant="destructive" size="sm"><Trash2 className="w-4 h-4 mr-2"/>Delete</Button></AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader><AlertDialogTitle>Are you sure?</AlertDialogTitle><AlertDialogDescription>This will permanently delete the selected tasks.</AlertDialogDescription></AlertDialogHeader>
              <AlertDialogFooter><AlertDialogCancel>Cancel</AlertDialogCancel><AlertDialogAction onClick={handleBulkDelete}>Delete</AlertDialogAction></AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
          <Button variant="outline" size="sm" onClick={handleBulkComplete}><CheckCircle className="w-4 h-4 mr-2"/>Complete</Button>
          <AlertDialog open={isReassignModalOpen} onOpenChange={setIsReassignModalOpen}>
            <AlertDialogTrigger asChild><Button variant="outline" size="sm"><RefreshCcw className="w-4 h-4 mr-2"/>Reassign</Button></AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader><AlertDialogTitle>Reassign Selected Tasks</AlertDialogTitle><AlertDialogDescription>
              <Combobox options={users} value={reassignTo} onChange={setReassignTo} placeholder="Select new assignee..."/>
              </AlertDialogDescription></AlertDialogHeader>
              <AlertDialogFooter><AlertDialogCancel>Cancel</AlertDialogCancel><AlertDialogAction onClick={handleBulkReassign}>Reassign</AlertDialogAction></AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </motion.div>
      )}

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div className="relative sm:col-span-full">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input type="text" placeholder="Search tasks or remarks..." value={filters.searchTerm} onChange={(e) => handleFilterChange('searchTerm', e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" />
          </div>
          <Combobox options={[{value: 'all', label: 'All Owners'}, ...users]} value={filters.owner} onChange={(val) => handleFilterChange('owner', val)} placeholder="Select Owner..."/>
          <Combobox options={[{value: 'all', label: 'All Assignees'}, ...users]} value={filters.assignee} onChange={(val) => handleFilterChange('assignee', val)} placeholder="Select Assignee..."/>
          <Combobox options={[{value: 'all', label: 'All Projects'}, ...sites]} value={filters.site} onChange={(val) => handleFilterChange('site', val)} placeholder="Select Project..."/>
          <select value={filters.status} onChange={(e) => handleFilterChange('status', e.target.value)} className="w-full px-3 py-2 border rounded-lg"><option value="all">All Status</option><option>Not Yet Started</option><option>In Progress</option><option>Overdue</option></select>
          <Popover><PopoverTrigger asChild><Button variant={"outline"} className={cn("w-full justify-start text-left font-normal", !filters.fromDate && "text-muted-foreground")}><CalendarIcon className="mr-2 h-4 w-4" />{filters.fromDate ? format(filters.fromDate, "PPP") : <span>From Date</span>}</Button></PopoverTrigger><PopoverContent className="w-auto p-0"><Calendar mode="single" selected={filters.fromDate} onSelect={(date) => handleFilterChange('fromDate', date)} initialFocus /></PopoverContent></Popover>
          <Popover><PopoverTrigger asChild><Button variant={"outline"} className={cn("w-full justify-start text-left font-normal", !filters.toDate && "text-muted-foreground")}><CalendarIcon className="mr-2 h-4 w-4" />{filters.toDate ? format(filters.toDate, "PPP") : <span>To Date</span>}</Button></PopoverTrigger><PopoverContent className="w-auto p-0"><Calendar mode="single" selected={filters.toDate} onSelect={(date) => handleFilterChange('toDate', date)} initialFocus /></PopoverContent></Popover>
        </div>

        <div className="hidden md:block overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b">
                <th className="p-2"><Checkbox onCheckedChange={(checked) => setSelectedRows(checked ? filteredTasks.map(t => t.id) : [])} /></th>
                <th className="p-2 text-left">S.No.</th>
                <th className="p-2 text-left">Task</th>
                <th className="p-2 text-left">Assignees</th>
                <th className="p-2 text-left">Owner</th>
                <th className="p-2 text-left">Project</th>
                <th className="p-2 text-left">Status</th>
                <th className="p-2 text-left">Remark</th>
                <th className="p-2 text-left">Due Date</th>
                <th className="p-2 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredTasks.map((task, index) => (
                <tr key={task.id} className="border-b hover:bg-gray-50/50">
                  <td className="p-2"><Checkbox checked={selectedRows.includes(task.id)} onCheckedChange={(checked) => setSelectedRows(prev => checked ? [...prev, task.id] : prev.filter(id => id !== task.id))} /></td>
                  <td className="p-2">{index + 1}</td>
                  <td className="p-2 font-medium">{task.task}</td>
                  <td className="p-2">{task.assignees.join(', ')}</td>
                  <td className="p-2">{task.taskOwners.join(', ')}</td>
                  <td className="p-2">{task.siteName}</td>
                  <td className="p-2"><span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusClass(task.status)}`}>{task.status}</span></td>
                  <td className="p-2 text-sm max-w-xs truncate">{task.last_update_remark}</td>
                  <td className="p-2">{task.due_date}</td>
                  <td className="p-2 flex gap-2">
                    <Button size="sm" variant="outline" onClick={() => { setSelectedTask(task); setIsUpdating(true); }}>Update</Button>
                    {canEdit(task) && <Button size="sm" variant="ghost" onClick={() => { setSelectedTask(task); setIsEditing(true); }}><FilePenLine className="w-4 h-4" /></Button>}
                    <Button size="sm" variant="ghost" onClick={() => { setSelectedTask(task); setIsViewing(true); }}><Info className="w-4 h-4" /></Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="md:hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
          {filteredTasks.map((task) => (
            <div key={task.id} className="p-4 rounded-lg shadow-md space-y-2 border">
              <div className="flex justify-between items-start">
                <p className="font-bold">{task.task}</p>
                <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusClass(task.status)}`}>{task.status}</span>
              </div>
              <p className="text-sm">Assignees: {task.assignees.join(', ')}</p>
              <p className="text-sm">Owner: {task.taskOwners.join(', ')}</p>
              <p className="text-sm">Project: {task.siteName}</p>
              <p className="text-sm truncate">Remark: {task.last_update_remark}</p>
              <p className="text-sm">Due: {task.due_date}</p>
              <div className="flex justify-end pt-2">
                <Button size="sm" variant="outline" onClick={() => { setSelectedTask(task); setIsUpdating(true); }}>Update</Button>
                {canEdit(task) && <Button size="sm" variant="ghost" onClick={() => { setSelectedTask(task); setIsEditing(true); }}><FilePenLine className="w-4 h-4" /></Button>}
                <Button size="sm" variant="ghost" onClick={() => { setSelectedTask(task); setIsViewing(true); }}><Info className="w-4 h-4" /></Button>
              </div>
            </div>
          ))}
        </div>

        {isLoading && <p className="text-center text-gray-500 py-8">Loading tasks...</p>}
        {!isLoading && filteredTasks.length === 0 && <p className="text-center text-gray-500 py-8">No pending tasks found for the selected filters.</p>}
      </motion.div>
      {isCreating && <TaskForm onSave={handleSaveTask} onClose={() => setIsCreating(false)} currentUser={currentUser} />}
      {isEditing && selectedTask && <TaskForm task={selectedTask} onSave={handleSaveTask} onClose={() => { setIsEditing(false); setSelectedTask(null); }} currentUser={currentUser} />}
      {isUpdating && selectedTask && <UpdateTaskModal task={selectedTask} onSave={handleSaveTask} onClose={() => { setIsUpdating(false); setSelectedTask(null); }} currentUser={currentUser} />}
      {isViewing && selectedTask && <TaskDetails task={selectedTask} onClose={() => { setIsViewing(false); setSelectedTask(null); }} />}
    </div>
  );
};

export default PendingTasks;
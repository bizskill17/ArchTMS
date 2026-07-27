import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Calendar, Clock, RotateCcw, Plus, Search, FileDown, Edit, Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import { exportToPDF, exportToExcel } from '@/lib/exportUtils';
import { supabase } from '@/lib/customSupabaseClient';

const RecurringTaskForm = ({ task, onSave, onClose, users, sites, categories, type }) => {
  const [formData, setFormData] = useState({
    task: '',
    categoryId: '',
    siteId: '',
    assigneeIds: [],
    taskOwnerIds: [],
    day: '', // For weekly
    monthDay: '', // For monthly
    dueMonth: '', // For yearly
    dueDay: '', // For yearly
  });

  useEffect(() => {
    if (task) {
      const taskDetails = task.task_details_json;
      const config = task.config_json;
      setFormData({
        task: taskDetails.task,
        categoryId: taskDetails.categoryId,
        siteId: taskDetails.siteId,
        assigneeIds: taskDetails.assigneeIds || [],
        taskOwnerIds: taskDetails.taskOwnerIds || [],
        day: config.day,
        monthDay: config.monthDay,
        dueMonth: config.dueMonth,
        dueDay: config.dueDay,
      });
    }
  }, [task]);

  const handleSubmit = (e) => {
    e.preventDefault();
    onSave(formData, type);
  };

  const assignableUsers = users.filter(u => u.role !== 'Developer');

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-lg w-full">
        <div className="flex justify-between items-center p-6 border-b">
          <h2 className="text-xl font-bold gradient-text">{task ? 'Edit' : 'Add'} Recurring Task</h2>
          <button onClick={onClose} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button>
        </div>
        <form onSubmit={handleSubmit} className="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
          <input type="text" required value={formData.task} onChange={(e) => setFormData({ ...formData, task: e.target.value })} placeholder="Task Name" className="w-full p-2 border rounded" />
          <select required value={formData.categoryId} onChange={(e) => setFormData({ ...formData, categoryId: e.target.value })} className="w-full p-2 border rounded"><option value="">Select Category</option>{categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
          <select required value={formData.siteId} onChange={(e) => setFormData({ ...formData, siteId: e.target.value })} className="w-full p-2 border rounded"><option value="">Select Site</option>{sites.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
          <select multiple required value={formData.assigneeIds} onChange={(e) => setFormData({ ...formData, assigneeIds: Array.from(e.target.selectedOptions, option => option.value) })} className="w-full p-2 border rounded h-24"><option value="">Select Assignees</option>{assignableUsers.map(u => <option key={u.id} value={u.id}>{u.full_name}</option>)}</select>
          <select multiple required value={formData.taskOwnerIds} onChange={(e) => setFormData({ ...formData, taskOwnerIds: Array.from(e.target.selectedOptions, option => option.value) })} className="w-full p-2 border rounded h-24"><option value="">Select Owners</option>{assignableUsers.map(u => <option key={u.id} value={u.id}>{u.full_name}</option>)}</select>
          
          {type === 'weekly' && <select required value={formData.day} onChange={(e) => setFormData({ ...formData, day: e.target.value })} className="w-full p-2 border rounded"><option value="">Select Day</option>{['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'].map((d, i) => <option key={i} value={i}>{d}</option>)}</select>}
          {type === 'monthly' && <input type="number" required min="1" max="31" value={formData.monthDay} onChange={(e) => setFormData({ ...formData, monthDay: e.target.value })} placeholder="Day of Month (1-31)" className="w-full p-2 border rounded" />}
          {type === 'yearly' && <div className="flex gap-4"><input type="number" required min="1" max="12" value={formData.dueMonth} onChange={(e) => setFormData({ ...formData, dueMonth: e.target.value })} placeholder="Month (1-12)" className="w-full p-2 border rounded" /><input type="number" required min="1" max="31" value={formData.dueDay} onChange={(e) => setFormData({ ...formData, dueDay: e.target.value })} placeholder="Day (1-31)" className="w-full p-2 border rounded" /></div>}

          <div className="flex justify-end space-x-4 pt-4">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit">{task ? 'Update' : 'Add'}</Button>
          </div>
        </form>
      </motion.div>
    </motion.div>
  );
};


const RecurringTasks = ({ currentUser }) => {
  const [activeTab, setActiveTab] = useState('daily');
  const [tasks, setTasks] = useState([]);
  const [filteredTasks, setFilteredTasks] = useState([]);
  const [filters, setFilters] = useState({ searchTerm: '', assignee: 'all', site: 'all' });
  const [users, setUsers] = useState([]);
  const [sites, setSites] = useState([]);
  const [categories, setCategories] = useState([]);
  const [showForm, setShowForm] = useState(false);
  const [selectedTask, setSelectedTask] = useState(null);
  const { toast } = useToast();

  const tabs = [
    { id: 'daily', label: 'Daily', icon: Calendar },
    { id: 'weekly', label: 'Weekly', icon: Clock },
    { id: 'monthly', label: 'Monthly', icon: RotateCcw },
    { id: 'yearly', label: 'Yearly', icon: Calendar }
  ];

  const loadData = useCallback(async () => {
    let query = supabase.from('tms_recurring_tasks').select('*').eq('type', activeTab);
    
    const { data, error } = await query;
    if (error) {
      toast({ title: "Error loading recurring tasks", description: error.message, variant: "destructive" });
    } else {
      setTasks(data);
    }

    if (users.length === 0) {
      const { data: usersData } = await supabase.from('tms_users').select('id, full_name, role');
      setUsers(usersData || []);
    }
    if (sites.length === 0) {
      const { data: sitesData } = await supabase.from('tms_sites').select('id, name');
      setSites(sitesData || []);
    }
    if (categories.length === 0) {
      const { data: categoriesData } = await supabase.from('tms_categories').select('id, name');
      setCategories(categoriesData || []);
    }
  }, [activeTab, toast, users.length, sites.length, categories.length]);

  useEffect(() => {
    loadData();
  }, [activeTab, loadData]);

  useEffect(() => {
    let currentTasks = [...tasks];
    if (filters.searchTerm) {
      currentTasks = currentTasks.filter(t => t.task_details_json.task.toLowerCase().includes(filters.searchTerm.toLowerCase()));
    }
    if (filters.assignee !== 'all') {
      currentTasks = currentTasks.filter(t => t.task_details_json.assigneeIds?.includes(filters.assignee));
    }
    if (filters.site !== 'all') {
      currentTasks = currentTasks.filter(t => t.task_details_json.siteId === filters.site);
    }
    setFilteredTasks(currentTasks);
  }, [tasks, filters]);

  const handleFilterChange = (filterName, value) => {
    setFilters(prev => ({ ...prev, [filterName]: value }));
  };

  const handleSave = async (formData, type) => {
    const task_details_json = {
      task: formData.task,
      categoryId: formData.categoryId,
      siteId: formData.siteId,
      assigneeIds: formData.assigneeIds,
      taskOwnerIds: formData.taskOwnerIds,
    };
    const config_json = {
      day: formData.day,
      monthDay: formData.monthDay,
      dueMonth: formData.dueMonth,
      dueDay: formData.dueDay,
    };

    const { error } = await supabase.from('tms_recurring_tasks').upsert({
      id: selectedTask?.id,
      type: type,
      task_details_json,
      config_json,
    });

    if (error) {
      toast({ title: "Error saving task", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: `Recurring task ${selectedTask ? 'updated' : 'added'}.` });
      setShowForm(false);
      setSelectedTask(null);
      loadData();
    }
  };

  const handleDelete = async (task) => {
    const { error } = await supabase.from('tms_recurring_tasks').delete().eq('id', task.id);
    if (error) {
      toast({ title: "Error deleting task", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: "Recurring task deleted." });
      loadData();
    }
  };

  const handleDownload = (format) => {
    const headers = ["S.No.", "Task", "Assignee(s)", "Owner(s)", "Project", "Details"];
    const data = filteredTasks.map((task, index) => [
      index + 1,
      task.task_details_json.task,
      task.task_details_json.assigneeIds.map(id => users.find(u => u.id === id)?.full_name).join(', '),
      task.task_details_json.taskOwnerIds.map(id => users.find(u => u.id === id)?.full_name).join(', '),
      sites.find(s => s.id === task.task_details_json.siteId)?.name || '',
      task.config_json.day || task.config_json.monthDay || (task.config_json.dueMonth && `${task.config_json.dueMonth}/${task.config_json.dueDay}`) || ''
    ]);
    const title = `${activeTab.charAt(0).toUpperCase() + activeTab.slice(1)}_Recurring_Tasks_Report`;
    if (format === 'Excel') {
      exportToExcel(data, headers, title);
    } else {
      exportToPDF(data, headers, title.replace(/_/g, ' '));
    }
  };

  const assignableUsers = users.filter(u => u.role !== 'Developer');

  const renderContent = () => (
    <motion.div initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }} className="space-y-4">
      <div className="hidden md:block overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="border-b">
              <th className="p-2 text-left">S.No.</th><th className="p-2 text-left">Task</th><th className="p-2 text-left">Assignee(s)</th><th className="p-2 text-left">Site</th><th className="p-2 text-left">Details</th><th className="p-2 text-left">Actions</th>
            </tr>
          </thead>
          <tbody>
            {filteredTasks.map((task, index) => (
              <tr key={task.id} className="border-b hover:bg-gray-50/50">
                <td className="p-2">{index + 1}</td>
                <td className="p-2 font-medium">{task.task_details_json.task}</td>
                <td className="p-2">{task.task_details_json.assigneeIds.map(id => users.find(u => u.id === id)?.full_name).join(', ')}</td>
                <td className="p-2">{sites.find(s => s.id === task.task_details_json.siteId)?.name}</td>
                <td className="p-2">{task.config_json.day ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][task.config_json.day] : task.config_json.monthDay || (task.config_json.dueMonth && `Month: ${task.config_json.dueMonth}, Day: ${task.config_json.dueDay}`) || ''}</td>
                <td className="p-2">
                  <div className="flex space-x-1">
                    <Button variant="ghost" size="icon" onClick={() => { setSelectedTask(task); setShowForm(true); }}><Edit className="w-4 h-4 text-green-600" /></Button>
                    <Button variant="ghost" size="icon" onClick={() => handleDelete(task)}><Trash2 className="w-4 h-4 text-red-600" /></Button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="md:hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
        {filteredTasks.map((task) => (
          <div key={task.id} className="p-4 rounded-lg shadow-md space-y-2 border">
            <p className="font-bold">{task.task_details_json.task}</p>
            <p className="text-sm">Assignee(s): {task.task_details_json.assigneeIds.map(id => users.find(u => u.id === id)?.full_name).join(', ')}</p>
            <p className="text-sm">Site: {sites.find(s => s.id === task.task_details_json.siteId)?.name}</p>
            <p className="text-sm">Schedule: {task.config_json.day ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][task.config_json.day] : task.config_json.monthDay || (task.config_json.dueMonth && `Month: ${task.config_json.dueMonth}, Day: ${task.config_json.dueDay}`) || 'Daily'}</p>
            <div className="flex space-x-2 pt-2">
              <Button variant="ghost" size="icon" onClick={() => { setSelectedTask(task); setShowForm(true); }}><Edit className="w-4 h-4 text-green-600" /></Button>
              <Button variant="ghost" size="icon" onClick={() => handleDelete(task)}><Trash2 className="w-4 h-4 text-red-600" /></Button>
            </div>
          </div>
        ))}
      </div>
      {filteredTasks.length === 0 && <p className="text-center py-8">No recurring tasks found for this period.</p>}
    </motion.div>
  );

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="flex flex-col md:flex-row justify-between md:items-center gap-4">
        <div className="w-full md:w-auto">
          <h1 className="text-3xl font-bold gradient-text">Recurring Tasks</h1>
          <p className="text-gray-600">Automate task creation with recurring schedules</p>
        </div>
        <div className="flex gap-2 w-full md:w-auto">
          <Button onClick={() => { setSelectedTask(null); setShowForm(true); }} className="bg-gradient-to-r from-blue-500 to-purple-600 flex-1 md:flex-none"><Plus className="w-4 h-4 mr-2" /> Add Task</Button>
          <Button onClick={() => handleDownload('Excel')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> Excel</Button>
          <Button onClick={() => handleDownload('PDF')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> PDF</Button>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <div className="flex flex-wrap gap-1 mb-6 border-b border-gray-200">
          {tabs.map((tab) => (
            <button key={tab.id} onClick={() => setActiveTab(tab.id)} className={`flex items-center space-x-2 px-3 py-2 text-sm sm:px-4 sm:py-3 rounded-t-lg transition-all duration-200 ${activeTab === tab.id ? 'bg-gradient-to-r from-blue-500 to-purple-600 text-white' : 'text-gray-600 hover:bg-gray-100'}`}>
              <tab.icon className="w-4 h-4" />
              <span className="font-medium">{tab.label}</span>
            </button>
          ))}
        </div>
        
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div className="relative sm:col-span-full md:col-span-2">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input type="text" placeholder="Search tasks..." value={filters.searchTerm} onChange={(e) => handleFilterChange('searchTerm', e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" />
          </div>
          <select value={filters.assignee} onChange={(e) => handleFilterChange('assignee', e.target.value)} className="w-full px-3 py-2 border rounded-lg"><option value="all">All Assignees</option>{assignableUsers.map(u => <option key={u.id} value={u.id}>{u.full_name}</option>)}</select>
          <select value={filters.site} onChange={(e) => handleFilterChange('site', e.target.value)} className="w-full px-3 py-2 border rounded-lg"><option value="all">All Sites</option>{sites.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
        </div>

        {renderContent()}
      </motion.div>
      {showForm && <RecurringTaskForm task={selectedTask} onSave={handleSave} onClose={() => { setShowForm(false); setSelectedTask(null); }} users={users} sites={sites} categories={categories} type={activeTab} />}
    </div>
  );
};

export default RecurringTasks;
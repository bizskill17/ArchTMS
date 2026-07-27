import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Plus, Search, Edit, Trash2, Eye, FileDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import VendorTaskForm from '@/components/VendorTaskForm';
import VendorTaskDetails from '@/components/VendorTaskDetails';
import { exportToPDF, exportToExcel } from '@/lib/exportUtils';
import { supabase } from '@/lib/customSupabaseClient';

const VendorTaskMaster = () => {
  const [vendorTasks, setVendorTasks] = useState([]);
  const [filteredTasks, setFilteredTasks] = useState([]);
  const [filters, setFilters] = useState({ searchTerm: '', status: 'all', owner: 'all', vendor: 'all' });
  const [showForm, setShowForm] = useState(false);
  const [showDetails, setShowDetails] = useState(false);
  const [selectedTask, setSelectedTask] = useState(null);
  const [users, setUsers] = useState([]);
  const [vendors, setVendors] = useState([]);
  const { toast } = useToast();

  const loadData = useCallback(async () => {
    const { data, error } = await supabase.from('tms_vendor_tasks').select('*, vendor:tms_vendors(name, email, mobile), owner:tms_users(full_name)');
    if (error) {
      toast({ title: "Error loading vendor tasks", description: error.message, variant: "destructive" });
    } else {
      setVendorTasks(data);
    }
    const { data: usersData } = await supabase.from('tms_users').select('id, full_name');
    setUsers(usersData || []);
    const { data: vendorsData } = await supabase.from('tms_vendors').select('id, name');
    setVendors(vendorsData || []);
  }, [toast]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    let filtered = vendorTasks;
    if (filters.searchTerm) filtered = filtered.filter(t => t.task.toLowerCase().includes(filters.searchTerm.toLowerCase()) || t.vendor.name.toLowerCase().includes(filters.searchTerm.toLowerCase()));
    if (filters.status !== 'all') filtered = filtered.filter(t => t.status === filters.status);
    if (filters.owner !== 'all') filtered = filtered.filter(t => t.owner_id === filters.owner);
    if (filters.vendor !== 'all') filtered = filtered.filter(t => t.vendor_id === filters.vendor);
    setFilteredTasks(filtered);
  }, [vendorTasks, filters]);

  const handleFilterChange = (filterName, value) => {
    setFilters(prev => ({ ...prev, [filterName]: value }));
  };

  const handleSaveTask = async (taskData) => {
    const { error } = await supabase.from('tms_vendor_tasks').upsert({ id: selectedTask?.id, ...taskData });
    if (error) {
      toast({ title: "Error saving task", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: `Vendor task ${selectedTask ? 'updated' : 'created'}.` });
      setShowForm(false);
      setSelectedTask(null);
      loadData();
    }
  };

  const handleDelete = async (taskId) => {
    const { error } = await supabase.from('tms_vendor_tasks').delete().eq('id', taskId);
    if (error) {
      toast({ title: "Error deleting task", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: "Vendor task deleted." });
      loadData();
    }
  };

  const getStatusColor = (status) => ({ 'Open': 'text-blue-600 bg-blue-100', 'In Progress': 'text-orange-600 bg-orange-100', 'Completed': 'text-green-600 bg-green-100', 'Overdue': 'text-red-600 bg-red-100' }[status] || 'text-gray-600 bg-gray-100');

  const handleDownload = (format) => {
    const headers = ["S.No.", "Task", "Vendor", "Owner", "Status", "Due Date", "Notes"];
    const data = filteredTasks.map((t, i) => [i + 1, t.task, t.vendor.name, t.owner.full_name, t.status, t.due_date, t.notes]);
    const title = `Vendor_Tasks_Report`;
    if (format === 'Excel') exportToExcel(data, headers, title);
    else exportToPDF(data, headers, title.replace(/_/g, ' '));
  };

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="flex flex-col md:flex-row justify-between md:items-center gap-4">
        <div className="w-full md:w-auto"><h1 className="text-3xl font-bold gradient-text">Vendor Tasks</h1><p className="text-gray-600">Manage vendor-specific tasks</p></div>
        <div className="flex gap-2 w-full md:w-auto"><Button onClick={() => { setSelectedTask(null); setShowForm(true); }} className="bg-gradient-to-r from-blue-500 to-purple-600 flex-1 md:flex-none"><Plus className="w-4 h-4 mr-2" /> Add Task</Button><Button onClick={() => handleDownload('Excel')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> Excel</Button><Button onClick={() => handleDownload('PDF')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> PDF</Button></div>
      </motion.div>
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div className="relative sm:col-span-full md:col-span-1"><Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" /><input type="text" placeholder="Search..." value={filters.searchTerm} onChange={(e) => handleFilterChange('searchTerm', e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" /></div>
          <select value={filters.status} onChange={(e) => handleFilterChange('status', e.target.value)} className="w-full px-3 py-2 border rounded-lg"><option value="all">All Status</option><option>Open</option><option>In Progress</option><option>Completed</option><option>Overdue</option></select>
          <select value={filters.owner} onChange={(e) => handleFilterChange('owner', e.target.value)} className="w-full px-3 py-2 border rounded-lg"><option value="all">All Owners</option>{users.map(u => <option key={u.id} value={u.id}>{u.full_name}</option>)}</select>
          <select value={filters.vendor} onChange={(e) => handleFilterChange('vendor', e.target.value)} className="w-full px-3 py-2 border rounded-lg"><option value="all">All Vendors</option>{vendors.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}</select>
        </div>
        <div className="hidden md:block overflow-x-auto">
          <table className="w-full">
            <thead><tr className="border-b"><th className="p-3 text-left">Task</th><th className="p-3 text-left">Vendor</th><th className="p-3 text-left">Owner</th><th className="p-3 text-left">Status</th><th className="p-3 text-left">Due Date</th><th className="p-3 text-left">Actions</th></tr></thead>
            <tbody>
              {filteredTasks.map((task) => (
                <motion.tr key={task.id} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="border-b hover:bg-gray-50/50">
                  <td className="p-3 font-medium">{task.task}</td><td className="p-3">{task.vendor?.name}</td><td className="p-3">{task.owner?.full_name}</td><td className="p-3"><span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(task.status)}`}>{task.status}</span></td><td className="p-3">{task.due_date}</td>
                  <td className="p-3"><div className="flex space-x-1"><button onClick={() => { setSelectedTask(task); setShowDetails(true); }} className="p-1 text-blue-600 hover:bg-blue-100 rounded"><Eye className="w-4 h-4" /></button><button onClick={() => { setSelectedTask(task); setShowForm(true); }} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button><button onClick={() => handleDelete(task.id)} className="p-1 text-red-600 hover:bg-red-100 rounded"><Trash2 className="w-4 h-4" /></button></div></td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="md:hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
          {filteredTasks.map((task) => (
            <motion.div key={task.id} className="p-4 rounded-lg shadow-md space-y-2 border">
              <div className="flex justify-between items-start"><p className="font-bold">{task.task}</p><span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(task.status)}`}>{task.status}</span></div>
              <p className="text-sm">Vendor: {task.vendor?.name}</p><p className="text-sm">Owner: {task.owner?.full_name}</p><p className="text-sm">Due: {task.due_date}</p>
              <div className="flex space-x-2 pt-2"><button onClick={() => { setSelectedTask(task); setShowDetails(true); }} className="p-1 text-blue-600 hover:bg-blue-100 rounded"><Eye className="w-4 h-4" /></button><button onClick={() => { setSelectedTask(task); setShowForm(true); }} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button><button onClick={() => handleDelete(task.id)} className="p-1 text-red-600 hover:bg-red-100 rounded"><Trash2 className="w-4 h-4" /></button></div>
            </motion.div>
          ))}
        </div>
        {filteredTasks.length === 0 && <div className="text-center py-8"><p className="text-gray-500">No vendor tasks found</p></div>}
      </motion.div>
      {showForm && <VendorTaskForm task={selectedTask} onSave={handleSaveTask} onClose={() => { setShowForm(false); setSelectedTask(null); }} />}
      {showDetails && selectedTask && <VendorTaskDetails task={selectedTask} onClose={() => { setShowDetails(false); setSelectedTask(null); }} />}
    </div>
  );
};

export default VendorTaskMaster;
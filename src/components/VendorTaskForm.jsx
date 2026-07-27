import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { supabase } from '@/lib/customSupabaseClient';
import { useToast } from '@/components/ui/use-toast';

const VendorTaskForm = ({ task, onSave, onClose }) => {
  const [formData, setFormData] = useState({ task: '', notes: '', due_date: '', vendor_id: '', owner_id: '', status: 'Open' });
  const [vendors, setVendors] = useState([]);
  const [users, setUsers] = useState([]);
  const { toast } = useToast();

  const loadDependencies = useCallback(async () => {
    const { data: vendorsData, error: vendorsError } = await supabase.from('tms_vendors').select('id, name');
    if (vendorsError) toast({ title: "Error loading vendors", variant: "destructive" }); else setVendors(vendorsData);
    const { data: usersData, error: usersError } = await supabase.from('tms_users').select('id, full_name');
    if (usersError) toast({ title: "Error loading users", variant: "destructive" }); else setUsers(usersData);
  }, [toast]);

  useEffect(() => {
    loadDependencies();
    if (task) {
      setFormData({ task: task.task, notes: task.notes, due_date: task.due_date, vendor_id: task.vendor_id, owner_id: task.owner_id, status: task.status });
    }
  }, [task, loadDependencies]);

  const handleSubmit = (e) => {
    e.preventDefault();
    onSave(formData);
  };

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
        <div className="flex justify-between items-center p-6 border-b"><h2 className="text-2xl font-bold gradient-text">{task ? 'Edit Vendor Task' : 'Create New Vendor Task'}</h2><button onClick={onClose} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button></div>
        <form onSubmit={handleSubmit} className="p-6 space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div><label className="block text-sm font-medium text-gray-700 mb-2">Task Name *</label><input type="text" required value={formData.task} onChange={(e) => setFormData({ ...formData, task: e.target.value })} className="w-full p-2 border rounded" /></div>
            <div><label className="block text-sm font-medium text-gray-700 mb-2">Vendor *</label><select required value={formData.vendor_id} onChange={(e) => setFormData({ ...formData, vendor_id: e.target.value })} className="w-full p-2 border rounded"><option value="">Select Vendor</option>{vendors.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}</select></div>
            <div><label className="block text-sm font-medium text-gray-700 mb-2">Task Owner *</label><select required value={formData.owner_id} onChange={(e) => setFormData({ ...formData, owner_id: e.target.value })} className="w-full p-2 border rounded"><option value="">Select Task Owner</option>{users.map(u => <option key={u.id} value={u.id}>{u.full_name}</option>)}</select></div>
            <div><label className="block text-sm font-medium text-gray-700 mb-2">Due Date</label><input type="date" value={formData.due_date} onChange={(e) => setFormData({ ...formData, due_date: e.target.value })} className="w-full p-2 border rounded" /></div>
            {task && <div><label className="block text-sm font-medium text-gray-700 mb-2">Status</label><select value={formData.status} onChange={(e) => setFormData({ ...formData, status: e.target.value })} className="w-full p-2 border rounded"><option>Open</option><option>In Progress</option><option>Completed</option><option>Overdue</option></select></div>}
          </div>
          <div><label className="block text-sm font-medium text-gray-700 mb-2">Notes</label><textarea value={formData.notes} onChange={(e) => setFormData({ ...formData, notes: e.target.value })} rows={3} className="w-full p-2 border rounded" /></div>
          <div className="flex justify-end space-x-4"><Button type="button" variant="outline" onClick={onClose}>Cancel</Button><Button type="submit" className="bg-gradient-to-r from-blue-500 to-purple-600">{task ? 'Update Task' : 'Create Task'}</Button></div>
        </form>
      </motion.div>
    </motion.div>
  );
};

export default VendorTaskForm;
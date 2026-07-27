import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { X, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import { supabase } from '@/lib/customSupabaseClient';
import { Combobox } from '@/components/ui/combobox';
import { notifyTaskActivity, sendMASMessage } from '@/lib/notificationUtils';

const UpdateTaskModal = ({ task, onSave, onClose, currentUser }) => {
  const [status, setStatus] = useState(task.status);
  const [remark, setRemark] = useState('');
  const [assigneeIds, setAssigneeIds] = useState(task.assigneeIds || []);
  const [users, setUsers] = useState([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { toast } = useToast();

  const loadUsers = useCallback(async () => {
    const { data, error } = await supabase.from('tms_users').select('id, full_name, is_active').order('full_name');
    if (error) {
      toast({ title: "Error loading users", description: error.message, variant: "destructive" });
    } else {
      setUsers(data || []);
    }
  }, [toast]);

  useEffect(() => {
    loadUsers();
  }, [loadUsers]);

  const handleSave = async () => {
    setIsSubmitting(true);
    if (status !== 'Completed' && !remark.trim()) {
      toast({ title: "Remark Required", description: "Please provide a remark when the status is not 'Completed'.", variant: "destructive" });
      setIsSubmitting(false);
      return;
    }

    const updatedTaskData = {
      status,
      last_update_remark: remark,
    };

    const { data: updatedTask, error: updateError } = await supabase.from('tms_tasks').update(updatedTaskData).eq('id', task.id).select('*, site:tms_sites(name, client_name, address)').single();

    if (updateError) {
      toast({ title: "Error updating task", description: updateError.message, variant: "destructive" });
      setIsSubmitting(false);
      return;
    }

    const { error: deleteError } = await supabase.from('tms_task_assignees').delete().eq('task_id', task.id);
    if (deleteError) { /* non-critical, log and continue */ console.error('Error clearing old assignees:', deleteError.message); }

    if (assigneeIds.length > 0) {
      const assigneesToInsert = assigneeIds.map(userId => ({ task_id: task.id, user_id: userId }));
      const { error: assigneeError } = await supabase.from('tms_task_assignees').insert(assigneesToInsert);
      if (assigneeError) { /* non-critical, log and continue */ console.error('Error re-assigning task:', assigneeError.message); }
    }

    await supabase.from('tms_action_log').insert({
      task_id: task.id,
      user_id: currentUser.id,
      status: status,
      remark: remark,
    });
    
    await notifyTaskActivity(task.id, 'Updated', currentUser.name, task.task, remark);

    // MAS notification to owners
    try {
        const { data: adminSettings } = await supabase.from('tms_admin_settings').select('whatsapp_group_id').limit(1).single();
        const { data: owners } = await supabase.from('tms_users').select('mobile').in('id', task.taskOwnerIds);
        const mobiles = (owners || []).map(o => o.mobile?.trim()).filter(Boolean);
        if (mobiles.length > 0 || adminSettings?.whatsapp_group_id) {
            const projectName = updatedTask.site ? `${updatedTask.site.client_name || ''} - ${updatedTask.site.name || ''}`.replace(/^ - | - $/g, '') : 'N/A';
            const dueDate = task.due_date ? new Date(task.due_date).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '-';
            const message = `*Task Updated*\n*Task:* ${task.task}\n*Updated By:* ${currentUser?.name || 'System'}\n*New Status:* ${status}\n*Project:* ${projectName}\n*Due Date:* ${dueDate}\n*Remark:* ${remark || '-'}\n\n`;
            await sendMASMessage({
                clientCode: 'BIZSKILL',
                message,
                mobiles: mobiles.length ? mobiles : undefined,
                groupNumber: adminSettings?.whatsapp_group_id || undefined,
            });
        }
    } catch (err) {
        console.warn('MAS notification to owners skipped or failed:', err);
    }

    toast({ title: "Success", description: "Task updated successfully!" });
    onSave();
    setIsSubmitting(false);
  };

  const activeUsers = users.filter(u => u.is_active).map(u => ({ value: u.id, label: u.full_name }));

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-lg w-full">
        <div className="flex justify-between items-center p-6 border-b">
          <h2 className="text-xl font-bold gradient-text">Update Task</h2>
          <button onClick={onClose} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button>
        </div>
        <div className="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Status</label>
            <select value={status} onChange={(e) => setStatus(e.target.value)} className="w-full p-2 border rounded">
              <option>Not Yet Started</option>
              <option>In Progress</option>
              <option>Completed</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Remarks {status !== 'Completed' && '*'}</label>
            <textarea value={remark} onChange={(e) => setRemark(e.target.value)} placeholder="Add remarks..." className="w-full p-2 border rounded" rows="3"></textarea>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Re-assign</label>
             <Combobox isMulti options={activeUsers} value={assigneeIds} onChange={(values) => setAssigneeIds(values)} placeholder="Select Assignees..." />
          </div>
        </div>
        <div className="flex justify-end space-x-4 p-6 border-t">
          <Button variant="outline" onClick={onClose} disabled={isSubmitting}>Cancel</Button>
          <Button onClick={handleSave} disabled={isSubmitting}>
             {isSubmitting ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Updating...</> : 'Update Task'}
          </Button>
        </div>
      </motion.div>
    </motion.div>
  );
};

export default UpdateTaskModal;
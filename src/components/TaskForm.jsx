import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { X, Plus, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import CategoryMaster from '@/components/CategoryMaster';
import SiteMaster from '@/components/SiteMaster';
import UserMaster from '@/components/UserMaster';
import { supabase } from '@/lib/customSupabaseClient';
import { Combobox } from '@/components/ui/combobox';
import { notifyTaskActivity, sendMASMessage } from '@/lib/notificationUtils';

const formatProjectLabel = (project) => {
  if (!project) return 'Unassigned';
  const parts = [project.client_name, project.name].filter(Boolean);
  if (parts.length === 0 && project.address) return project.address;
  let mainPart = parts.join(' - ');
  if (project.address) mainPart += ` (${project.address})`;
  return mainPart || 'Unnamed Project';
};

const QuickAddModal = ({ title, children, onClose }) => (
  <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/60 flex items-center justify-center z-[60] p-4">
    <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[80vh] flex flex-col">
      <div className="flex justify-between items-center p-4 border-b">
        <h2 className="text-xl font-bold gradient-text">{title}</h2>
        <button type="button" onClick={onClose} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button>
      </div>
      <div className="p-4 overflow-y-auto">{children}</div>
    </motion.div>
  </motion.div>
);

const TaskForm = ({ task, onSave, onClose, currentUser }) => {
  const [formData, setFormData] = useState({
    task: '', notes: '', priority: 'Medium', assigneeIds: [], taskOwnerIds: [], categoryId: '', siteId: '', due_date: '', templateId: '',
  });
  const [users, setUsers] = useState([]);
  const [categories, setCategories] = useState([]);
  const [sites, setSites] = useState([]);
  const [templates, setTemplates] = useState([]);
  const [showQuickAdd, setShowQuickAdd] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { toast } = useToast();

  const loadData = useCallback(async () => {
    const { data: usersData, error: usersError } = await supabase.from('tms_users').select('id, full_name, is_active').order('full_name');
    if (usersError) toast({ title: 'Error loading users', description: usersError.message, variant: 'destructive' });
    else setUsers(usersData || []);

    const { data: categoriesData } = await supabase.from('tms_categories').select('id, name').order('name');
    setCategories((categoriesData || []).map(c => ({ value: String(c.id), label: c.name })));
    
    const { data: sitesData } = await supabase.from('tms_sites').select('id, name, client_name, address').order('name');
    setSites((sitesData || []).map(s => ({ value: String(s.id), label: formatProjectLabel(s) })));

    const { data: templatesData, error: templatesError } = await supabase.from('tms_task_templates').select('id, name, is_active').eq('is_active', true).order('name');
    if (templatesError) toast({ title: 'Error loading task templates', description: templatesError.message, variant: 'destructive' });
    else setTemplates((templatesData || []).map(t => ({ value: String(t.id), label: t.name })));

    return { usersData: usersData || [] };
  }, [toast]);

  useEffect(() => {
    loadData().then(({ usersData }) => {
      if (task) {
        setFormData({
          task: task.task, notes: task.notes || '', priority: task.priority,
          assigneeIds: (task.assigneeIds || []).map(String),
          taskOwnerIds: (task.taskOwnerIds || []).map(String),
          categoryId: String(task.category_id || ''),
          siteId: String(task.site_id || ''),
          due_date: task.due_date || '', templateId: String(task.template_id || ''),
        });
      } else {
        const owner = usersData.find(u => String(u.id) === String(currentUser.id));
        if (owner) setFormData(prev => ({ ...prev, taskOwnerIds: [String(owner.id)] }));
      }
    });
  }, [task, currentUser.id, loadData]);

  const role = String(currentUser?.role || '').toLowerCase().trim();
  const isPrivileged = role === 'developer' || role === 'admin';
  const isOwner = (task?.taskOwnerIds ?? formData.taskOwnerIds ?? []).map(String).includes(String(currentUser?.id ?? ''));
  const canEditTaskDetails = isPrivileged || isOwner || !task;

  const QuickAddButton = ({ type }) => {
    if (!isPrivileged) return null;
    return <Button type="button" size="icon" variant="ghost" className="ml-2" onClick={() => setShowQuickAdd(type)}><Plus className="w-4 h-4" /></Button>;
  };

  const renderQuickAddModal = () => {
    if (!showQuickAdd) return null;
    const handleClose = () => { loadData(); setShowQuickAdd(null); };
    switch (showQuickAdd) {
      case 'user': return <QuickAddModal title="Add User" onClose={handleClose}><UserMaster currentUser={currentUser} isModal={true} /></QuickAddModal>;
      case 'category': return <QuickAddModal title="Add Category" onClose={handleClose}><CategoryMaster isModal={true} /></QuickAddModal>;
      case 'site': return <QuickAddModal title="Add Project" onClose={handleClose}><SiteMaster isModal={true} /></QuickAddModal>;
      default: return null;
    }
  };

  const handleTemplateChange = (val) => {
    setFormData(prev => ({ ...prev, templateId: val || '', task: templates.find(t => t.value === val)?.label || prev.task }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsSubmitting(true);
    try {
      if (!formData.task || !formData.priority || !formData.assigneeIds.length || !formData.taskOwnerIds.length || !formData.categoryId || !formData.siteId || !formData.due_date) {
        toast({ title: 'Missing Fields', description: 'Please fill all mandatory fields (*).', variant: 'destructive' });
        setIsSubmitting(false);
        return;
      }

      const taskData = {
        task: formData.task, notes: formData.notes, priority: formData.priority, category_id: formData.categoryId, site_id: formData.siteId, due_date: formData.due_date, template_id: formData.templateId || null, status: task ? task.status : 'Not Yet Started',
      };
      
      let savedTask, actionStatus, remark;
      if (task) {
        const { data, error } = await supabase.from('tms_tasks').update(taskData).eq('id', task.id).select().single();
        if (error) throw error; savedTask = data; actionStatus = 'Updated'; remark = 'Task details updated.';
      } else {
        const { data, error } = await supabase.from('tms_tasks').insert(taskData).select().single();
        if (error) throw error; savedTask = data; actionStatus = 'Created'; remark = 'Task created.';
      }

      await supabase.from('tms_task_owners').delete().eq('task_id', savedTask.id);
      await supabase.from('tms_task_assignees').delete().eq('task_id', savedTask.id);
      if (formData.taskOwnerIds.length) await supabase.from('tms_task_owners').insert(formData.taskOwnerIds.map(uid => ({ task_id: savedTask.id, user_id: uid })));
      if (formData.assigneeIds.length) await supabase.from('tms_task_assignees').insert(formData.assigneeIds.map(uid => ({ task_id: savedTask.id, user_id: uid })));

      await supabase.from('tms_action_log').insert({ task_id: savedTask.id, user_id: currentUser.id, status: actionStatus, remark });
      await notifyTaskActivity(savedTask.id, actionStatus, currentUser.name, formData.task, remark);

      try {
        const { data: adminSettings } = await supabase.from('tms_admin_settings').select('whatsapp_group_id').limit(1).single();
        const { data: assignees } = await supabase.from('tms_users').select('mobile').in('id', formData.assigneeIds);
        const mobiles = (assignees || []).map(a => a.mobile?.trim()).filter(Boolean);
        if (mobiles.length || adminSettings?.whatsapp_group_id) {
          const assigneeName = activeUsers.find(u => u.value === formData.assigneeIds[0])?.label || 'N/A';
          const projectName = sites.find(s => s.value === formData.siteId)?.label || 'N/A';
          const dueDate = formData.due_date ? new Date(formData.due_date).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '-';
          await sendMASMessage({
            clientCode: 'BIZSKILL',
            message: `*New Task Assigned to:* ${assigneeName}\n*Task:* ${formData.task}\n*Assigned By:* ${currentUser?.name || 'System'}\n*Project:* ${projectName}\n*Due Date:* ${dueDate}\n*Notes :* ${formData.notes || '-'}\n\n`,
            mobiles: mobiles.length ? mobiles : undefined,
            groupNumber: adminSettings?.whatsapp_group_id || undefined,
          });
        }
      } catch (err) { console.warn('MAS notification skipped or failed:', err); }

      toast({ title: 'Success', description: `Task ${task ? 'updated' : 'created'} successfully.` });
      onSave(formData);
    } catch (error) {
      console.error(error);
      toast({ title: 'Error', description: error.message, variant: 'destructive' });
    }
    setIsSubmitting(false);
  };

  const activeUsers = users.filter(u => u.is_active).map(u => ({ value: String(u.id), label: u.full_name }));
  const allUsers = users.map(u => ({ value: String(u.id), label: u.full_name }));

  return (
    <>
      {renderQuickAddModal()}
      <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] flex flex-col">
          <div className="flex justify-between items-center p-6 border-b">
            <h2 className="text-2xl font-bold gradient-text">{task ? 'Edit Task' : 'Add Task'}</h2>
            <button type="button" onClick={onClose} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button>
          </div>
          <form onSubmit={handleSubmit} className="p-6 space-y-6 flex-grow overflow-y-auto">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div><label className="block text-sm font-medium text-gray-700 mb-2">Task</label><Combobox options={templates} value={formData.templateId} onChange={handleTemplateChange} placeholder="Select a task..." disabled={!canEditTaskDetails} /></div>
              <div className="hidden"><label className="block text-sm font-medium text-gray-700 mb-2">Task Name *</label><input type="text" value={formData.task} onChange={(e) => setFormData({ ...formData, task: e.target.value })} className="w-full p-2 border rounded" disabled={!canEditTaskDetails} /></div>
              <div><label className="block text-sm font-medium text-gray-700 mb-2">Priority *</label><select value={formData.priority} onChange={(e) => setFormData({ ...formData, priority: e.target.value })} className="w-full p-2 border rounded" disabled={!canEditTaskDetails}><option>Low</option><option>Medium</option><option>High</option></select></div>
              <div><label className="block text-sm font-medium text-gray-700 mb-2">Assignees *</label><Combobox isMulti options={activeUsers} value={formData.assigneeIds} onChange={(values) => setFormData(f => ({ ...f, assigneeIds: values }))} placeholder="Select Assignees..." disabled={!canEditTaskDetails} /></div>
              <div><label className="block text-sm font-medium text-gray-700 mb-2">Task Owners *</label><Combobox isMulti options={allUsers} value={formData.taskOwnerIds} onChange={(values) => setFormData(f => ({ ...f, taskOwnerIds: values }))} placeholder="Select Owners..." disabled={!canEditTaskDetails} /></div>
              <div><label className="block text-sm font-medium text-gray-700 mb-2">Category *</label><div className="flex items-center"><Combobox options={categories} value={formData.categoryId} onChange={(val) => setFormData(f => ({ ...f, categoryId: val }))} placeholder="Select Category..." disabled={!canEditTaskDetails} />{canEditTaskDetails && <QuickAddButton type="category" />}</div></div>
              <div><label className="block text-sm font-medium text-gray-700 mb-2">Project *</label><div className="flex items-center"><Combobox options={sites} value={formData.siteId} onChange={(val) => setFormData(f => ({ ...f, siteId: val }))} placeholder="Select Project..." disabled={!canEditTaskDetails} />{canEditTaskDetails && <QuickAddButton type="site" />}</div></div>
              <div><label className="block text-sm font-medium text-gray-700 mb-2">Due Date *</label><input type="date" value={formData.due_date} onChange={(e) => setFormData({ ...formData, due_date: e.target.value })} className="w-full p-2 border rounded" disabled={!canEditTaskDetails} /></div>
              {task && (<div><label className="block text-sm font-medium text-gray-700 mb-2">Status</label><input type="text" value={task.status} className="w-full p-2 border rounded bg-gray-100" readOnly /></div>)}
            </div>
            <div><label className="block text-sm font-medium text-gray-700 mb-2">Notes</label><textarea value={formData.notes} onChange={(e) => setFormData({ ...formData, notes: e.target.value })} rows={3} className="w-full p-2 border rounded" disabled={!canEditTaskDetails} /></div>
            <div className="flex justify-end space-x-4 pt-4">
              <Button type="button" variant="outline" onClick={onClose} disabled={isSubmitting}>Cancel</Button>
              <Button type="submit" className="bg-gradient-to-r from-blue-500 to-purple-600" disabled={isSubmitting || !canEditTaskDetails}>{isSubmitting ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Saving...</> : task ? 'Save Changes' : 'Create Task'}</Button>
            </div>
          </form>
        </motion.div>
      </motion.div>
    </>
  );
};

export default TaskForm;
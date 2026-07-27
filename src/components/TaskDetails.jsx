import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { X, Paperclip, Clock, User, Tag, MapPin, Calendar, Info } from 'lucide-react';
import { supabase } from '@/lib/customSupabaseClient';
import { useToast } from '@/components/ui/use-toast';
import { format } from 'date-fns';

const TaskDetails = ({ task, onClose }) => {
  const [actionLog, setActionLog] = useState([]);
  const { toast } = useToast();

  const loadActionLog = useCallback(async () => {
    const { data, error } = await supabase
      .from('tms_action_log')
      .select('*, user:tms_users!user_id(full_name)')
      .eq('task_id', task.id)
      .order('created_at', { ascending: false });

    if (error) {
      toast({ title: "Error loading action log", description: error.message, variant: "destructive" });
    } else {
      setActionLog(data);
    }
  }, [task.id, toast]);

  useEffect(() => {
    loadActionLog();
  }, [loadActionLog]);

  const DetailItem = ({ icon, label, value }) => (
    <div className="flex items-start space-x-3">
      <div className="mt-1">{icon}</div>
      <div>
        <p className="text-sm font-medium text-gray-500">{label}</p>
        <p className="text-base text-gray-800">{value}</p>
      </div>
    </div>
  );

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <motion.div initial={{ scale: 0.9, y: 50 }} animate={{ scale: 1, y: 0 }} className="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] flex flex-col">
        <div className="flex justify-between items-center p-6 border-b">
          <h2 className="text-2xl font-bold gradient-text">{task.task}</h2>
          <button onClick={onClose} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button>
        </div>
        <div className="flex-grow overflow-y-auto p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <DetailItem icon={<Info className="w-5 h-5 text-blue-500" />} label="Status" value={task.status} />
            <DetailItem icon={<Calendar className="w-5 h-5 text-red-500" />} label="Due Date" value={task.due_date} />
            <DetailItem icon={<User className="w-5 h-5 text-green-500" />} label="Assignees" value={task.assignees?.join(', ') || 'N/A'} />
            <DetailItem icon={<User className="w-5 h-5 text-purple-500" />} label="Owners" value={task.taskOwners?.join(', ') || 'N/A'} />
            <DetailItem icon={<Tag className="w-5 h-5 text-yellow-500" />} label="Category" value={task.category || 'N/A'} />
            <DetailItem icon={<MapPin className="w-5 h-5 text-indigo-500" />} label="Project" value={task.siteName || 'N/A'} />
          </div>
          
          {task.notes && (
            <div className="mb-6">
              <h3 className="text-lg font-semibold text-gray-800 mb-2">Notes</h3>
              <p className="text-gray-600 bg-gray-50 p-3 rounded-lg">{task.notes}</p>
            </div>
          )}

          {(task.photo_url || task.pdf_url) && (
            <div className="mb-6">
              <h3 className="text-lg font-semibold text-gray-800 mb-2">Attachments</h3>
              <div className="flex space-x-4">
                {task.photo_url && <a href={task.photo_url} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">View Photo</a>}
                {task.pdf_url && <a href={task.pdf_url} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">View PDF</a>}
              </div>
            </div>
          )}

          <div>
            <h3 className="text-lg font-semibold text-gray-800 mb-4">Activity Log</h3>
            <div className="space-y-4">
              {actionLog.length > 0 ? actionLog.map(log => (
                <div key={log.id} className="flex space-x-3">
                  <div className="flex-shrink-0">
                    <div className="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
                      <User className="w-4 h-4 text-gray-600" />
                    </div>
                  </div>
                  <div>
                    <p className="text-sm">
                      <span className="font-semibold">{log.user?.full_name || 'System'}</span>
                      <span className="text-gray-500"> updated status to </span>
                      <span className="font-semibold">{log.status}</span>
                    </p>
                    {log.remark && <p className="text-sm bg-gray-100 p-2 rounded-md mt-1">{log.remark}</p>}
                    <p className="text-xs text-gray-400 mt-1">{format(new Date(log.created_at), "PPp")}</p>
                  </div>
                </div>
              )) : <p className="text-gray-500">No activity recorded yet.</p>}
            </div>
          </div>
        </div>
      </motion.div>
    </motion.div>
  );
};

export default TaskDetails;
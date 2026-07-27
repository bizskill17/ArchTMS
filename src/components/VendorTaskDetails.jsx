import React from 'react';
import { motion } from 'framer-motion';
import { X, Calendar, User, Building2, AlertCircle } from 'lucide-react';

const VendorTaskDetails = ({ task, onClose }) => {
  const getStatusColor = (status) => ({
    'Open': 'text-blue-600 bg-blue-100',
    'In Progress': 'text-orange-600 bg-orange-100',
    'Completed': 'text-green-600 bg-green-100',
    'Overdue': 'text-red-600 bg-red-100'
  }[status] || 'text-gray-600 bg-gray-100');

  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
        <div className="flex justify-between items-center p-6 border-b"><h2 className="text-2xl font-bold gradient-text">Vendor Task Details</h2><button onClick={onClose} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button></div>
        <div className="p-6 space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="space-y-4">
              <div>
                <h3 className="text-lg font-semibold text-gray-900 mb-2">Task Information</h3>
                <div className="space-y-3">
                  <div><label className="text-sm font-medium text-gray-500">Task Name</label><p className="font-semibold text-gray-900">{task.task}</p></div>
                  <div><label className="text-sm font-medium text-gray-500">Status</label><span className={`inline-block px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(task.status)}`}>{task.status}</span></div>
                </div>
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900 mb-2">Vendor Details</h3>
                <div className="space-y-3"><div className="flex items-center space-x-2"><Building2 className="w-4 h-4 text-gray-400" /><div><label className="text-sm font-medium text-gray-500">Vendor</label><p className="font-medium">{task.vendor?.name}</p><p className="text-sm text-gray-600">{task.vendor?.email}</p><p className="text-sm text-gray-600">{task.vendor?.mobile}</p></div></div></div>
              </div>
            </div>
            <div className="space-y-4">
              <div>
                <h3 className="text-lg font-semibold text-gray-900 mb-2">Timeline & Ownership</h3>
                <div className="space-y-3">
                  <div className="flex items-center space-x-2"><Calendar className="w-4 h-4 text-gray-400" /><div><label className="text-sm font-medium text-gray-500">Created Date</label><p className="font-medium">{new Date(task.created_at).toLocaleDateString()}</p></div></div>
                  <div className="flex items-center space-x-2"><AlertCircle className="w-4 h-4 text-gray-400" /><div><label className="text-sm font-medium text-gray-500">Due Date</label><p className="font-medium">{task.due_date}</p></div></div>
                  <div className="flex items-center space-x-2"><User className="w-4 h-4 text-gray-400" /><div><label className="text-sm font-medium text-gray-500">Task Owner</label><p className="font-medium">{task.owner?.full_name}</p></div></div>
                </div>
              </div>
            </div>
          </div>
          {task.notes && <div><h3 className="text-lg font-semibold text-gray-900 mb-2">Notes</h3><div className="bg-gray-50 p-4 rounded-lg"><p className="text-gray-700">{task.notes}</p></div></div>}
        </div>
      </motion.div>
    </motion.div>
  );
};

export default VendorTaskDetails;
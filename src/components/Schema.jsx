import React from 'react';
import { motion } from 'framer-motion';
import { Database } from 'lucide-react';

const Schema = () => {
  const schemaItems = [
    { name: 'tms_tasks', fields: ['id (PK)', 'created_at', 'task', 'notes', 'priority', 'category_id (FK)', 'site_id (FK)', 'due_date', 'status', 'last_update_remark', 'recurring_source_id'] },
    { name: 'tms_task_assignees', fields: ['id (PK)', 'task_id (FK)', 'user_id (FK)'] },
    { name: 'tms_task_owners', fields: ['id (PK)', 'task_id (FK)', 'user_id (FK)'] },
    { name: 'tms_action_log', fields: ['id (PK)', 'created_at', 'task_id (FK)', 'user_id (FK)', 'status', 'remark', 'update_document_url', 'reassigned_to_user_id (FK)'] },
    { name: 'tms_users', fields: ['id (PK, UUID from auth.users)', 'created_at', 'full_name', 'email', 'mobile', 'designation_id (FK)', 'role'] },
    { name: 'tms_designations', fields: ['id (PK)', 'created_at', 'name'] },
    { name: 'tms_categories', fields: ['id (PK)', 'created_at', 'name'] },
    { name: 'tms_sites', fields: ['id (PK)', 'created_at', 'name', 'address', 'telegram_group_id'] },
    { name: 'tms_vendors', fields: ['id (PK)', 'created_at', 'name', 'mobile', 'email', 'address'] },
    { name: 'tms_vendor_tasks', fields: ['id (PK)', 'created_at', 'task', 'notes', 'vendor_id (FK)', 'owner_id (FK)', 'status', 'due_date'] },
    { name: 'tms_recurring_tasks', fields: ['id (PK)', 'created_at', 'type', 'config_json', 'task_details_json'] },
  ];

  return (
    <div className="space-y-8">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }}>
        <h1 className="text-3xl font-bold gradient-text">Application Schema (for Supabase)</h1>
        <p className="text-gray-600">Overview of data structures for the database.</p>
      </motion.div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {schemaItems.map((item, index) => (
          <motion.div
            key={item.name}
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: index * 0.05 }}
            className="task-card"
          >
            <h2 className="text-xl font-semibold mb-4 flex items-center gradient-text">
              <Database className="w-5 h-5 mr-2 text-blue-500" /> {item.name}
            </h2>
            <ul className="space-y-1 text-sm text-gray-700">
              {item.fields.map(field => (
                <li key={field} className="p-1 rounded hover:bg-gray-100 font-mono text-xs">{field}</li>
              ))}
            </ul>
          </motion.div>
        ))}
      </div>
    </div>
  );
};

export default Schema;
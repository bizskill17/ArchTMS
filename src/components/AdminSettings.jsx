import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Settings as SettingsIcon, Image as ImageIcon, Building, Clock } from 'lucide-react';
import { useToast } from '@/components/ui/use-toast';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { supabase } from '@/lib/customSupabaseClient';
import { initializeScheduler } from '@/lib/taskProcessor';

const AdminSettings = ({ onUpdate }) => {
  const [settings, setSettings] = useState({
    id: null,
    orgName: '',
    logoUrl: '',
    notifications: {
      taskAssignment: true,
      taskUpdate: true,
    },
    whatsapp: {
      apiKey: '',
      apiSecret: '',
      personalMessages: true,
      taskAssignedTemplate: 'New Task Assigned: {taskName} | Due: {dueDate}',
      taskUpdatedTemplate: 'Task Updated: {taskName} | Status: {status}',
      pendingTaskReminder: true,
    },
    telegram: {
      botToken: '',
      reportingGroupId: '',
      sendDailyReport: true,
    },
    reportTriggers: {
      pendingTasksTime: '',
      dailyActivityTime: '',
    },
    mas_username: '',
    mas_password: '',
    whatsapp_group_id: '',
  });

  const { toast } = useToast();

  const loadSettings = useCallback(async () => {
    const { data, error } = await supabase
      .from('tms_admin_settings')
      .select('*')
      .limit(1)
      .single();

    if (error && error.code !== 'PGRST116') {
      toast({
        title: 'Error loading settings',
        description: error.message,
        variant: 'destructive',
      });
      return;
    }

    if (data) {
      setSettings(prev => ({
        ...prev,
        id: data.id ?? null,
        orgName: data.org_name || '',
        logoUrl: data.logo_url || '',
        notifications: data.notifications || prev.notifications,
        whatsapp: data.whatsapp || prev.whatsapp,
        telegram: data.telegram || prev.telegram,
        reportTriggers: {
          pendingTasksTime: data.report_triggers?.pendingTasksTime || '',
          dailyActivityTime: data.report_triggers?.dailyActivityTime || '',
        },
        mas_username: data.mas_username || '',
        mas_password: data.mas_password || '',
        whatsapp_group_id: data.whatsapp_group_id || '',
      }));
    }
  }, [toast]);

  useEffect(() => {
    loadSettings();
  }, [loadSettings]);

  const handleSettingChange = (section, key, value) => {
    setSettings(prev => {
      if (section) {
        return { ...prev, [section]: { ...prev[section], [key]: value } };
      }
      return { ...prev, [key]: value };
    });
  };

  const handleLogoUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const filePath = `public/${Date.now()}-${file.name}`;
    const { error: uploadError } = await supabase.storage.from('logos').upload(filePath, file);

    if (uploadError) {
      toast({
        title: 'Logo Upload Failed',
        description: uploadError.message,
        variant: 'destructive',
      });
      return;
    }

    const { data: { publicUrl } } = supabase.storage.from('logos').getPublicUrl(filePath);
    handleSettingChange(null, 'logoUrl', publicUrl);
    toast({ title: 'Logo Uploaded', description: "Click 'Save All Settings' to apply." });
  };

  const saveSettings = async () => {
    const settingsToSave = {
      id: settings.id || undefined,
      org_name: settings.orgName,
      logo_url: settings.logoUrl,
      notifications: settings.notifications,
      whatsapp: settings.whatsapp,
      telegram: settings.telegram,
      report_triggers: {
        pendingTasksTime: settings.reportTriggers.pendingTasksTime || null,
        dailyActivityTime: settings.reportTriggers.dailyActivityTime || null,
      },
      mas_username: settings.mas_username || null,
      mas_password: settings.mas_password || null,
      whatsapp_group_id: settings.whatsapp_group_id || null,
      updated_at: new Date().toISOString(),
    };

    if (!settings.id) {
      const { data, error: fetchError } = await supabase
        .from('tms_admin_settings')
        .select('id')
        .single();
      if (!fetchError && data) {
        settingsToSave.id = data.id;
      }
    }

    const { error } = await supabase
      .from('tms_admin_settings')
      .upsert(settingsToSave, { onConflict: 'id' });

    if (error) {
      toast({
        title: 'Error Saving Settings',
        description: error.message,
        variant: 'destructive',
      });
    } else {
      onUpdate?.({ orgName: settings.orgName, logoUrl: settings.logoUrl });
      toast({ title: 'Settings Saved', description: 'Your settings have been updated successfully.' });
      initializeScheduler?.();
    }
  };

  return (
    <div className="space-y-8">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }}>
        <h1 className="text-3xl font-bold gradient-text">Admin Settings</h1>
        <p className="text-gray-600">Manage application-wide settings</p>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <h2 className="text-xl font-semibold mb-4 flex items-center">
          <Building className="w-5 h-5 mr-2" /> Organization
        </h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Organization Name</label>
            <input
              type="text"
              value={settings.orgName}
              onChange={e => handleSettingChange(null, 'orgName', e.target.value)}
              className="w-full p-2 border rounded"
              placeholder="Your Company Name"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Organization Logo</label>
            <div className="flex items-center gap-4">
              <input
                type="file"
                id="logo-upload"
                accept="image/*"
                onChange={handleLogoUpload}
                className="hidden"
              />
              <label
                htmlFor="logo-upload"
                className="cursor-pointer bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-lg flex items-center"
              >
                <ImageIcon className="w-4 h-4 mr-2" /> Upload Logo
              </label>
              {settings.logoUrl && (
                <img
                  src={settings.logoUrl}
                  alt="Logo Preview"
                  className="h-10 w-auto object-contain rounded-md bg-gray-100 p-1"
                />
              )}
            </div>
          </div>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0, transition: { delay: 0.25 } }} className="task-card">
        <h2 className="text-xl font-semibold mb-4">WhatsApp Messaging (MAS)</h2>
        <div className="border p-4 rounded-lg space-y-4">
          <div>
            <label className="block text-sm font-medium">MAS Username</label>
            <input
              type="text"
              value={settings.mas_username || ''}
              onChange={e => handleSettingChange(null, 'mas_username', e.target.value)}
              className="w-full border p-2 rounded mt-1"
              placeholder="Enter MAS Username"
            />
          </div>
          <div>
            <label className="block text-sm font-medium">MAS Password</label>
            <input
              type="password"
              value={settings.mas_password || ''}
              onChange={e => handleSettingChange(null, 'mas_password', e.target.value)}
              className="w-full border p-2 rounded mt-1"
              placeholder="Enter MAS Password"
            />
          </div>
          <div>
            <label className="block text-sm font-medium">WhatsApp Group ID</label>
            <input
              type="text"
              value={settings.whatsapp_group_id || ''}
              onChange={e => handleSettingChange(null, 'whatsapp_group_id', e.target.value)}
              className="w-full border p-2 rounded mt-1"
              placeholder="Enter Group ID"
            />
          </div>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0, transition: { delay: 0.3 } }} className="task-card">
        <h2 className="text-xl font-semibold mb-4 flex items-center">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="mr-2">
            <path d="M22 2 11 13"></path>
            <path d="m22 2-7 20-4-9-9-4 20-7z"></path>
          </svg>
          Telegram Notifications
        </h2>
        <div className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Bot Token</label>
            <input
              type="password"
              value={settings.telegram.botToken}
              onChange={e => handleSettingChange('telegram', 'botToken', e.target.value)}
              className="w-full p-2 border rounded"
              placeholder="Enter Telegram Bot Token"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Reporting Group Chat ID</label>
            <input
              type="text"
              value={settings.telegram.reportingGroupId}
              onChange={e => handleSettingChange('telegram', 'reportingGroupId', e.target.value)}
              className="w-full p-2 border rounded"
              placeholder="Enter Telegram Group Chat ID"
            />
          </div>
          <div className="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50">
            <span>Send Daily Activity Report to Reporting Group</span>
            <Switch
              checked={settings.telegram.sendDailyReport}
              onCheckedChange={val => handleSettingChange('telegram', 'sendDailyReport', val)}
            />
          </div>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0, transition: { delay: 0.4 } }} className="task-card">
        <h2 className="text-xl font-semibold mb-4 flex items-center">
          <Clock className="w-5 h-5 mr-2" /> Report Triggers
        </h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Pending Tasks Reminder Time</label>
            <input
              type="time"
              value={settings.reportTriggers.pendingTasksTime || ''}
              onChange={e => handleSettingChange('reportTriggers', 'pendingTasksTime', e.target.value)}
              className="w-full p-2 border rounded"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Daily Activity Report Time</label>
            <input
              type="time"
              value={settings.reportTriggers.dailyActivityTime || ''}
              onChange={e => handleSettingChange('reportTriggers', 'dailyActivityTime', e.target.value)}
              className="w-full p-2 border rounded"
            />
          </div>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0, transition: { delay: 0.5 } }} className="flex justify-end">
        <Button onClick={saveSettings} className="bg-gradient-to-r from-blue-500 to-purple-600">
          <SettingsIcon className="w-4 h-4 mr-2" />
        </Button>
      </motion.div>
    </div>
  );
};

export default AdminSettings;
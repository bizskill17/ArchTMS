import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Power, MessageSquare, Link, Eye } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import { supabase } from '@/lib/customSupabaseClient';

const DeveloperSettings = ({ onUpdate, onImpersonate, currentUser }) => {
  const [settings, setSettings] = useState({
    id: null,
    isAppDisabled: false,
    disabledMessage: '',
    developerUrl: ''
  });
  const [users, setUsers] = useState([]);
  const [selectedUserId, setSelectedUserId] = useState('');
  const { toast } = useToast();

  const loadSettings = useCallback(async () => {
    const { data, error } = await supabase.from('tms_developer_settings').select('*').limit(1).single();
    if (error && error.code !== 'PGRST116') {
      toast({ title: "Error loading dev settings", description: error.message, variant: "destructive" });
    } else if (data) {
      setSettings({
        id: data.id,
        isAppDisabled: data.is_app_disabled,
        disabledMessage: data.disabled_message,
        developerUrl: data.developer_url
      });
    }

    const { data: usersData, error: usersError } = await supabase.from('tms_users').select('id, full_name, role, email, tms_designations(name)').neq('id', currentUser.id);
    if (usersError) {
      toast({ title: "Error loading users", description: usersError.message, variant: "destructive" });
    } else {
      setUsers(usersData.map(u => ({...u, designation: u.tms_designations?.name || 'N/A'})));
    }
  }, [toast, currentUser.id]);

  useEffect(() => {
    loadSettings();
  }, [loadSettings]);

  const handleSave = async () => {
    const settingsToSave = {
      id: settings.id,
      is_app_disabled: settings.isAppDisabled,
      disabled_message: settings.disabledMessage,
      developer_url: settings.developerUrl,
      updated_at: new Date().toISOString(),
    };
    const { error } = await supabase.from('tms_developer_settings').upsert(settingsToSave, { onConflict: 'id' });

    if (error) {
      toast({ title: "Error Saving Settings", description: error.message, variant: "destructive" });
    } else {
      onUpdate({ isAppDisabled: settings.isAppDisabled, disabledMessage: settings.disabledMessage });
      toast({ title: "Developer Settings Saved", description: "The application settings have been updated." });
    }
  };

  const handleViewAs = () => {
    if (!selectedUserId) {
      toast({ title: "No User Selected", description: "Please select a user to view as.", variant: "destructive" });
      return;
    }
    const userToImpersonate = users.find(u => u.id === selectedUserId);
    if (userToImpersonate) {
      onImpersonate({
        id: userToImpersonate.id,
        name: userToImpersonate.full_name,
        email: userToImpersonate.email,
        role: userToImpersonate.role,
        designation: userToImpersonate.designation
      });
    }
  };

  return (
    <div className="space-y-8">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }}>
        <h1 className="text-3xl font-bold gradient-text">Developer Settings</h1>
        <p className="text-gray-600">High-level application controls</p>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <h2 className="text-xl font-semibold mb-4 flex items-center"><Power className="w-5 h-5 mr-2" /> Application Control</h2>
        <div className="space-y-4">
          <div className="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50">
            <label htmlFor="app-disable-switch" className="font-medium">Disable App for Non-Developers</label>
            <Switch id="app-disable-switch" checked={settings.isAppDisabled} onCheckedChange={(val) => setSettings(p => ({...p, isAppDisabled: val}))} />
          </div>
          <div className="p-3 rounded-lg">
            <label htmlFor="disabled-message" className="font-medium flex items-center mb-2"><MessageSquare className="w-4 h-4 mr-2" /> Custom Disabled Message</label>
            <textarea
              id="disabled-message"
              value={settings.disabledMessage}
              onChange={(e) => setSettings(p => ({...p, disabledMessage: e.target.value}))}
              placeholder="Enter a message to show when the app is disabled..."
              className="w-full p-2 border rounded-lg"
              rows="3"
            />
          </div>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0, transition: { delay: 0.1 } }} className="task-card">
        <h2 className="text-xl font-semibold mb-4 flex items-center"><Eye className="w-5 h-5 mr-2" /> Impersonation</h2>
        <div className="p-3 rounded-lg space-y-4">
          <p className="text-sm text-gray-600">Test the application from another user's perspective.</p>
          <div className="flex flex-col sm:flex-row items-center gap-4">
            <select value={selectedUserId} onChange={(e) => setSelectedUserId(e.target.value)} className="w-full sm:w-auto flex-grow p-2 border rounded-lg">
              <option value="">Select a user to view as...</option>
              {users.map(user => (
                <option key={user.id} value={user.id}>{user.full_name} ({user.role})</option>
              ))}
            </select>
            <Button onClick={handleViewAs} className="w-full sm:w-auto">
              <Eye className="w-4 h-4 mr-2" /> View As User
            </Button>
          </div>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0, transition: { delay: 0.2 } }} className="task-card">
        <h2 className="text-xl font-semibold mb-4 flex items-center"><Link className="w-5 h-5 mr-2" /> Branding</h2>
        <div className="p-3 rounded-lg">
          <label htmlFor="developer-url" className="font-medium flex items-center mb-2"><Link className="w-4 h-4 mr-2" /> "Developed by" URL</label>
          <input
            id="developer-url"
            type="url"
            value={settings.developerUrl}
            onChange={(e) => setSettings(p => ({...p, developerUrl: e.target.value}))}
            placeholder="https://example.com"
            className="w-full p-2 border rounded-lg"
          />
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.3 }} className="flex justify-end">
        <Button onClick={handleSave} className="bg-gradient-to-r from-green-500 to-teal-600">
          Save Settings
        </Button>
      </motion.div>
    </div>
  );
};

export default DeveloperSettings;
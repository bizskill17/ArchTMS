import React, { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import { useToast } from '@/components/ui/use-toast';
import { useNavigate } from 'react-router-dom';
import { supabase } from '@/lib/customSupabaseClient';

const UpdatePasswordPage = () => {
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [session, setSession] = useState(null);
  const [booting, setBooting] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const { toast } = useToast();
  const navigate = useNavigate();

  useEffect(() => {
    (async () => {
      try {
        // If arriving from email link, exchange code for a temporary session
        const url = window.location.href;
        const hasCode =
          url.includes('code=') ||
          url.includes('#access_token=') ||
          url.includes('refresh_token=');

        if (hasCode) {
          try {
            await supabase.auth.exchangeCodeForSession(url);
          } catch (err) {
            // Ignore if already exchanged or not present
          }
        }

        // Read current session (should exist for a valid recovery link)
        const { data: { session } } = await supabase.auth.getSession();
        setSession(session || null);
      } finally {
        setBooting(false);
      }
    })();
  }, []);

  const handlePasswordUpdate = async (e) => {
    e.preventDefault();

    if (!password) {
      toast({
        variant: 'destructive',
        title: 'Password required',
        description: 'Please enter a new password.',
      });
      return;
    }

    if (password !== confirm) {
      toast({
        variant: 'destructive',
        title: 'Passwords do not match',
        description: 'Please retype the password.',
      });
      return;
    }

    setSubmitting(true);
    const { error } = await supabase.auth.updateUser({ password });

    if (error) {
      toast({
        variant: 'destructive',
        title: 'Error updating password',
        description: error.message || 'Something went wrong.',
      });
      setSubmitting(false);
      return;
    }

    toast({
      title: 'Password updated',
      description: 'Please sign in with your new password.',
    });

    // End the temporary recovery session and go to sign-in
    await supabase.auth.signOut();
    navigate('/auth'); // adjust to your actual sign-in route
  };

  // Loading state while we validate link/session
  if (booting) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-gray-50">
        <div className="text-center text-gray-700">Preparing reset form…</div>
      </div>
    );
  }

  // Invalid/expired link (no session available)
  if (!session) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-gray-100">
        <div className="text-center max-w-md">
          <h1 className="text-2xl font-bold">Invalid or expired link</h1>
          <p className="text-gray-600 mt-2">
            Please request a new reset link and open it on this device.
          </p>
        </div>
      </div>
    );
  }

  // Password update form
  return (
    <div className="flex items-center justify-center min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-indigo-100">
      <motion.div
        initial={{ opacity: 0, y: -20 }}
        animate={{ opacity: 1, y: 0 }}
        className="w-full max-w-md p-8 space-y-8 bg-white rounded-2xl shadow-2xl"
      >
        <div className="text-center">
          <h1 className="text-3xl font-bold">Update Password</h1>
          <p className="text-gray-600">Enter your new password below.</p>
        </div>

        <form className="space-y-6" onSubmit={handlePasswordUpdate}>
          <div>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="New Password"
              required
              className="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div>
            <input
              type="password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              placeholder="Confirm Password"
              required
              className="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <button
            type="submit"
            disabled={submitting}
            className="w-full flex justify-center items-center py-3 px-4 rounded-lg text-sm font-medium text-white bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 disabled:opacity-50"
          >
            {submitting ? 'Updating…' : 'Update Password'}
          </button>
        </form>
      </motion.div>
    </div>
  );
};

export default UpdatePasswordPage;
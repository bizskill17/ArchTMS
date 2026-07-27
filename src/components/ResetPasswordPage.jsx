import React, { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import { useToast } from '@/components/ui/use-toast';
import { supabase } from '@/lib/customSupabaseClient';
import { useAuth } from '@/contexts/SupabaseAuthContext';
import { useNavigate } from 'react-router-dom';
import { Eye, EyeOff, Loader, AlertTriangle } from 'lucide-react';

const ResetPasswordPage = () => {
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [pageState, setPageState] = useState('loading'); // 'loading' | 'ready' | 'error'
  const { toast } = useToast();
  const navigate = useNavigate();
  const { setIsRecoveryFlow } = useAuth();

  // ⬇️ freeze URL params once so later URL cleanup doesn’t affect us
  const [frozenParams] = useState(() => {
    const hash = window.location.hash || '';
    const search = window.location.search || '';

    const hashParams = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : hash);
    const searchParams = new URLSearchParams(search);

    return {
      type: hashParams.get('type') || searchParams.get('type'),
      errorDesc: hashParams.get('error_description') || searchParams.get('error_description'),
    };
  });

  useEffect(() => {
    if (frozenParams.errorDesc) {
      setError(frozenParams.errorDesc);
      setPageState('error');
      setIsRecoveryFlow(false);
    } else if (frozenParams.type === 'recovery') {
      setIsRecoveryFlow(true);
      setPageState('ready');
    } else {
      setError('Invalid or expired password reset link. Please request a new one.');
      setPageState('error');
      setIsRecoveryFlow(false);
    }

    // keep listener but don’t clear hash
    const { data: { subscription } } = supabase.auth.onAuthStateChange(() => {
      // no-op
    });

    return () => {
      try {
        subscription.unsubscribe();
      } catch (e) {
        // ignore
      }
    };
  }, [frozenParams, setIsRecoveryFlow]);

  const handlePasswordUpdate = async (e) => {
    e.preventDefault();

    if (!password || password.length < 6) {
      toast({
        title: 'Password too short',
        description: 'Please enter at least 6 characters.',
        variant: 'destructive',
      });
      return;
    }

    setLoading(true);

    const { error: updateError } = await supabase.auth.updateUser({ password });

    if (updateError) {
      const desc = updateError?.message || 'Unexpected error updating password.';
      toast({
        title: 'Error Updating Password',
        description: desc,
        variant: 'destructive',
      });
      setError(desc);
      setPageState('error');
    } else {
      toast({
        title: 'Success!',
        description: 'Your password has been updated. You can now sign in.',
      });
      setIsRecoveryFlow(false);
      // sign out to clear old session
      try {
        await supabase.auth.signOut({ scope: 'global' });
      } catch (e) {
        // ignore
      }
      navigate('/auth', { replace: true });
    }

    setLoading(false);
  };

  if (pageState === 'loading') {
    return (
      <div className="flex items-center justify-center min-h-screen bg-gray-100">
        <div className="text-center">
          <Loader className="animate-spin text-blue-500 h-10 w-10 mb-4" />
          <h1 className="text-2xl font-bold">Verifying Link...</h1>
        </div>
      </div>
    );
  }

  if (pageState === 'error') {
    return (
      <div className="flex items-center justify-center min-h-screen bg-gray-100">
        <div className="text-center p-8 max-w-md mx-auto bg-white shadow-lg rounded-lg border-t-4 border-red-500">
          <AlertTriangle className="w-12 h-12 text-red-500 mx-auto mb-4" />
          <h1 className="text-2xl font-bold text-red-600">Password Reset Failed</h1>
          <p className="text-gray-700 mt-2">{error}</p>
          <button
            onClick={() => navigate('/auth')}
            className="mt-6 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          >
            Back to Sign In
          </button>
        </div>
      </div>
    );
  }

  // pageState === 'ready'
  return (
    <div className="flex items-center justify-center min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-indigo-100">
      <motion.div
        initial={{ opacity: 0, y: -20 }}
        animate={{ opacity: 1, y: 0 }}
        className="w-full max-w-md p-8 space-y-8 bg-white rounded-2xl shadow-2xl"
      >
        <div className="text-center">
          <h1 className="text-3xl font-bold">Create a New Password</h1>
          <p className="text-gray-600">
            Enter your new password below. It must be at least 6 characters long.
          </p>
        </div>
        <form className="space-y-6" onSubmit={handlePasswordUpdate}>
          <div className="relative">
            <input
              type={showPassword ? 'text' : 'password'}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="New Password"
              required
              minLength={6}
              className="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500"
              aria-label={showPassword ? 'Hide password' : 'Show password'}
            >
              {showPassword ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
            </button>
          </div>
          <button
            type="submit"
            disabled={loading || !password}
            className="w-full flex justify-center items-center py-3 px-4 rounded-lg text-sm font-medium text-white bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 disabled:opacity-50"
          >
            {loading ? <Loader className="animate-spin w-5 h-5" /> : 'Update Password'}
          </button>
        </form>
      </motion.div>
    </div>
  );
};

export default ResetPasswordPage;
import React, {
  createContext,
  useContext,
  useEffect,
  useState,
  useCallback,
  useMemo,
} from 'react';
import { supabase } from '@/lib/customSupabaseClient';
import { useToast } from '@/components/ui/use-toast';
import { useNavigate } from 'react-router-dom';

const AuthContext = createContext(undefined);

export const AuthProvider = ({ children }) => {
  const { toast } = useToast();
  const navigate = useNavigate();

  const [user, setUser] = useState(null);
  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(true);
  const [isRecoveryFlow, setIsRecoveryFlow] = useState(false);

  // MAIN auth listener
  useEffect(() => {
    const {
      data: { subscription },
    } = supabase.auth.onAuthStateChange((event, newSession) => {
      if (event === 'PASSWORD_RECOVERY') {
        setIsRecoveryFlow(true);
        setSession(newSession);
        setUser(newSession?.user ?? null);
        setLoading(false);
        return;
      }

      if (isRecoveryFlow) {
        if (event === 'SIGNED_OUT') setIsRecoveryFlow(false);
        return;
      }

      setSession(newSession);
      setUser(newSession?.user ?? null);
      setLoading(false);
    });

    supabase.auth.getSession().then(({ data: { session } }) => {
      if (!isRecoveryFlow) {
        setSession(session);
        setUser(session?.user ?? null);
      }
      setLoading(false);
    });

    return () => subscription.unsubscribe();
  }, [isRecoveryFlow]);

  // 🔁 AFTER we actually have a session, claim the row if needed
  useEffect(() => {
    const linkIfNeeded = async () => {
      if (!user?.email || !user?.id) return;

      // check if tms_users already has this user_id
      const { data: existing, error: existingErr } = await supabase
        .from('tms_users')
        .select('id, user_id')
        .eq('user_id', user.id)
        .maybeSingle();

      if (existingErr) {
        console.warn('check profile error', existingErr.message);
        return;
      }

      // already linked → nothing to do
      if (existing) return;

      // try to claim the pre-registered row that matches this email
      const { error: linkError } = await supabase
        .from('tms_users')
        .update({ user_id: user.id })
        .eq('email', user.email.toLowerCase())
        .is('user_id', null);

      if (linkError) {
        console.warn('auto-link after login failed:', linkError.message);
      }
    };

    linkIfNeeded();
  }, [user]);

  // ----------------- SIGN UP -----------------
  const signUp = useCallback(
    async (email, password, options) => {
      const normalizedEmail = email.trim().toLowerCase();

      // precheck in tms_users
      const { data: preRegisteredUser, error: checkError } = await supabase
        .from('tms_users')
        .select('id, is_active, user_id')
        .ilike('email', normalizedEmail)
        .limit(1)
        .maybeSingle();

      if (checkError) {
        toast({ variant: 'destructive', title: 'Sign up Error', description: checkError.message });
        return { data: null, error: checkError };
      }

      if (!preRegisteredUser) {
        toast({
          variant: 'destructive',
          title: 'Sign up Failed',
          description: 'This email is not registered. Please contact an administrator.',
        });
        return { data: null, error: { message: 'User not pre-registered.' } };
      }

      if (!preRegisteredUser.is_active) {
        toast({
          variant: 'destructive',
          title: 'Sign up Failed',
          description: 'This user account is inactive. Please contact an administrator.',
        });
        return { data: null, error: { message: 'User is inactive.' } };
      }

      if (preRegisteredUser.user_id) {
        toast({
          variant: 'destructive',
          title: 'Sign up Failed',
          description: 'This email has already been registered. Please sign in or reset your password.',
        });
        return { data: null, error: { message: 'User already has an account.' } };
      }

      // create auth user (will send email)
      const { data, error } = await supabase.auth.signUp({
        email: normalizedEmail,
        password,
        options: {
          ...options,
          emailRedirectTo: 'https://tms.bizskilledu.com/auth',
        },
      });

      if (error) {
        toast({
          variant: 'destructive',
          title: 'Sign up Failed',
          description: error.message || 'Something went wrong',
        });
      } else {
        toast({
          title: 'Sign up Successful',
          description: 'Please check your email for a verification link.',
        });
      }

      // NOTE: we do NOT rely on this moment to link, because user is not authenticated yet.
      // The linking will happen in the useEffect above when the user comes back authenticated.

      return { data, error };
    },
    [toast]
  );

  // ----------------- SIGN IN -----------------
  const signIn = useCallback(
    async (email, password) => {
      setIsRecoveryFlow(false);
      const normalizedEmail = email.trim().toLowerCase();

      const {
        data: { user: authUser },
        error: authError,
      } = await supabase.auth.signInWithPassword({
        email: normalizedEmail,
        password,
      });

      if (authError) {
        toast({
          variant: 'destructive',
          title: 'Sign in Failed',
          description: authError.message || 'Invalid credentials.',
        });
        return { error: authError };
      }

      // after sign in, the linking effect above will run
      // but we can still try to fetch profile now
      const { data: userProfile, error: profileError } = await supabase
        .from('tms_users')
        .select('id, is_active, role')
        .eq('user_id', authUser.id)
        .maybeSingle();

      if (profileError || !userProfile) {
        // do NOT sign out immediately — give the linking effect a chance
        toast({
          variant: 'destructive',
          title: 'Profile Error',
          description: 'User profile not found. Please contact support.',
        });
        return { error: profileError || new Error('User profile not found.') };
      }

      if (!userProfile.is_active) {
        await supabase.auth.signOut({ scope: 'global' });
        toast({
          variant: 'destructive',
          title: 'Sign in Failed',
          description: 'This account is inactive. Please contact an administrator.',
        });
        return { error: { message: 'User is inactive.' } };
      }

      toast({ title: 'Sign in Successful!', description: 'Welcome back!' });
      return { error: null };
    },
    [toast]
  );

  const signOut = useCallback(async () => {
    setIsRecoveryFlow(false);
    await supabase.auth.signOut({ scope: 'global' });
    navigate('/auth', { replace: true });
  }, [navigate]);

  const resetPassword = useCallback(
    async (email) => {
      const { error } = await supabase.auth.resetPasswordForEmail(email, {
        redirectTo: 'https://tms.bizskilledu.com/reset-password',
      });

      if (error) {
        toast({
          variant: 'destructive',
          title: 'Password Reset Failed',
          description: error.message,
        });
      } else {
        toast({
          title: 'Password Reset Email Sent',
          description: 'Please check your email for instructions.',
        });
      }
      return { error };
    },
    [toast]
  );

  const value = useMemo(
    () => ({
      user,
      session,
      loading,
      isRecoveryFlow,
      setIsRecoveryFlow,
      signUp,
      signIn,
      signOut,
      resetPassword,
    }),
    [user, session, loading, isRecoveryFlow, signUp, signIn, signOut, resetPassword]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};
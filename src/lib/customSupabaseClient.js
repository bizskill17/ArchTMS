import { createClient } from '@supabase/supabase-js';

const supabaseUrl = 'https://muycexxftsrnddawyvdj.supabase.co';
const supabaseAnonKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im11eWNleHhmdHNybmRkYXd5dmRqIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTU0MTM3NDUsImV4cCI6MjA3MDk4OTc0NX0.vOrhkslNr8jdT0t4KLPzUspwnlTbYfgxj6XdEmDg2wA';

const customSupabaseClient = createClient(supabaseUrl, supabaseAnonKey);

export default customSupabaseClient;

export { 
    customSupabaseClient,
    customSupabaseClient as supabase,
};

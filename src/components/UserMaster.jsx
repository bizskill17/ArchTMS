import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Plus, Search, Edit, Trash2, X, ToggleLeft, ToggleRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import DesignationMaster from '@/components/DesignationMaster';
import { supabase } from '@/lib/customSupabaseClient';
import { Combobox } from '@/components/ui/combobox';

const QuickAddModal = ({ title, children, onClose }) => (
  <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/60 flex items-center justify-center z-[60] p-4">
    <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[80vh] overflow-y-auto">
      <div className="flex justify-between items-center p-4 border-b">
        <h2 className="text-xl font-bold gradient-text">{title}</h2>
        <button onClick={onClose} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button>
      </div>
      <div className="p-4">{children}</div>
    </motion.div>
  </motion.div>
);

const UserMaster = ({ currentUser, openForm, onFormHandled }) => {
  const [users, setUsers] = useState([]);
  const [filteredUsers, setFilteredUsers] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [selectedUser, setSelectedUser] = useState(null);
  const [designations, setDesignations] = useState([]);
  const [showDesignationModal, setShowDesignationModal] = useState(false);
  const [formData, setFormData] = useState({ id: '', full_name: '', email: '', mobile: '', designation_id: '', role: 'Employee', is_active: true });
  const { toast } = useToast();

  useEffect(() => {
    if (openForm) {
      resetForm();
      setShowForm(true);
      if (onFormHandled) onFormHandled();
    }
  }, [openForm, onFormHandled]);

  const loadData = useCallback(async () => {
    const { data: usersData, error: usersError } = await supabase.from('tms_users').select('*, tms_designations(name)').order('full_name');
    if (usersError) toast({ title: "Error loading users", description: usersError.message, variant: "destructive" });
    else setUsers(usersData.map(u => ({ ...u, designation: u.tms_designations?.name })));

    const { data: designationsData, error: designationsError } = await supabase.from('tms_designations').select('*').order('name');
    if (designationsError) toast({ title: "Error loading designations", description: designationsError.message, variant: "destructive" });
    else setDesignations(designationsData?.map(d => ({ value: d.id, label: d.name })) || []);
  }, [toast]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    let filtered = users;
    if (searchTerm) {
      filtered = filtered.filter(user =>
        (user.full_name && user.full_name.toLowerCase().includes(searchTerm.toLowerCase())) ||
        (user.email && user.email.toLowerCase().includes(searchTerm.toLowerCase())) ||
        (user.designation && user.designation.toLowerCase().includes(searchTerm.toLowerCase()))
      );
    }
    setFilteredUsers(filtered);
  }, [users, searchTerm]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (formData.mobile && !/^\d{10}$/.test(formData.mobile)) { toast({ title: "Invalid Mobile Number", variant: "destructive" }); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) { toast({ title: "Invalid Email Address", variant: "destructive" }); return; }

    const isDuplicate = users.some(
      u => u.email.toLowerCase() === formData.email.trim().toLowerCase() && u.id !== selectedUser?.id
    );

    if (isDuplicate) {
      toast({ title: "Duplicate Email", description: "This email address is already in use.", variant: "destructive" });
      return;
    }

    const userData = {
      full_name: formData.full_name,
      email: formData.email.trim(),
      mobile: formData.mobile,
      designation_id: formData.designation_id,
      role: formData.role,
      is_active: formData.is_active,
    };

    let query;
    if (selectedUser) {
      query = supabase.from('tms_users').update(userData).eq('id', selectedUser.id);
    } else {
      query = supabase.from('tms_users').insert([userData]);
    }

    const { error } = await query;

    if (error) {
      toast({ title: `Error ${selectedUser ? 'updating' : 'adding'} user`, description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: `User ${selectedUser ? 'updated' : 'pre-registered'}. They can now sign up.` });
      loadData();
      resetForm();
    }
  };

  const handleEdit = (user) => {
    setSelectedUser(user);
    setFormData({ id: user.id, full_name: user.full_name, email: user.email, mobile: user.mobile, designation_id: user.designation_id, role: user.role, is_active: user.is_active === null ? true : user.is_active });
    setShowForm(true);
  };

  const handleDelete = async (userToDelete) => {
    if (userToDelete.email === 'bizskill17@gmail.com') {
      toast({ title: "Action Forbidden", description: "The default developer user cannot be deleted.", variant: "destructive" });
      return;
    }
    const { error } = await supabase.from('tms_users').delete().eq('id', userToDelete.id);
    if (error) toast({ title: "Error deleting user", description: error.message, variant: "destructive" });
    else { toast({ title: "Success", description: "User deleted successfully!" }); loadData(); }
  };
  
  const toggleActiveStatus = async (user) => {
    const newStatus = user.is_active === null ? false : !user.is_active;
    const { error } = await supabase.from('tms_users').update({ is_active: newStatus }).eq('id', user.id);
    if (error) {
      toast({ title: "Error updating status", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: `User status updated.` });
      loadData();
    }
  };

  const resetForm = () => {
    setFormData({ id: '', full_name: '', email: '', mobile: '', designation_id: '', role: 'Employee', is_active: true });
    setSelectedUser(null);
    setShowForm(false);
  };

  return (
    <>
      {showDesignationModal && <QuickAddModal title="Manage Designations" onClose={() => { loadData(); setShowDesignationModal(false); }}><DesignationMaster /></QuickAddModal>}
      <div className="space-y-6">
        <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }}>
          <h1 className="text-3xl font-bold gradient-text mb-2">Users</h1>
          <p className="text-gray-600">Pre-register users and manage their roles</p>
        </motion.div>

        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
          <div className="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
            <div className="relative w-full md:flex-1">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
              <input type="text" placeholder="Search users..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" />
            </div>
            <Button onClick={() => { resetForm(); setShowForm(true); }} className="bg-gradient-to-r from-blue-500 to-purple-600 w-full md:w-auto"><Plus className="w-4 h-4 mr-2" /> Pre-register User</Button>
          </div>

          <div className="hidden md:block overflow-x-auto">
            <table className="w-full">
              <thead><tr className="border-b"><th className="p-3 text-left">S.No.</th><th className="p-3 text-left">Name</th><th className="p-3 text-left">Email</th><th className="p-3 text-left">Mobile</th><th className="p-3 text-left">Designation</th><th className="p-3 text-left">Role</th><th className="p-3 text-left">Status</th><th className="p-3 text-left">Actions</th></tr></thead>
              <tbody>
                {filteredUsers.map((user, index) => (
                  <motion.tr key={user.id} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="border-b hover:bg-gray-50/50">
                    <td className="p-3">{index + 1}</td><td className="p-3 font-medium">{user.full_name}</td><td className="p-3">{user.email}</td><td className="p-3">{user.mobile}</td><td className="p-3">{user.designation}</td><td className="p-3">{user.role}</td>
                    <td className="p-3">
                      <button onClick={() => toggleActiveStatus(user)} className={`p-1 rounded-full ${user.is_active ? 'text-green-600' : 'text-red-600'}`}>
                        {user.is_active ? <ToggleRight className="w-6 h-6" /> : <ToggleLeft className="w-6 h-6" />}
                      </button>
                    </td>
                    <td className="p-3"><div className="flex space-x-2"><button onClick={() => handleEdit(user)} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button><button onClick={() => handleDelete(user)} className="p-1 text-red-600 hover:bg-red-100 rounded" disabled={user.email === 'bizskill17@gmail.com'}><Trash2 className="w-4 h-4" /></button></div></td>
                  </motion.tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="md:hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
            {filteredUsers.map((user) => (
              <motion.div key={user.id} className="p-4 rounded-lg shadow-md space-y-2 border">
                <div className="flex justify-between items-start"><div><p className="font-bold">{user.full_name}</p><p className="text-sm text-gray-600">{user.designation}</p></div><span className={`text-sm font-medium px-2 py-1 rounded-full ${user.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>{user.is_active ? 'Active' : 'Inactive'}</span></div>
                <p className="text-sm text-gray-700">{user.email}</p><p className="text-sm text-gray-700">{user.mobile}</p>
                <div className="flex justify-between items-center pt-2">
                  <span className="text-sm font-medium bg-blue-100 text-blue-800 px-2 py-1 rounded-full">{user.role}</span>
                  <div className="flex space-x-2"><button onClick={() => handleEdit(user)} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button><button onClick={() => handleDelete(user)} className="p-1 text-red-600 hover:bg-red-100 rounded" disabled={user.email === 'bizskill17@gmail.com'}><Trash2 className="w-4 h-4" /></button></div>
                </div>
              </motion.div>
            ))}
          </div>

          {filteredUsers.length === 0 && <div className="text-center py-8"><p className="text-gray-500">No users found</p></div>}
        </motion.div>

        {showForm && (
          <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-md w-full">
              <div className="flex justify-between items-center p-6 border-b"><h2 className="text-xl font-bold gradient-text">{selectedUser ? 'Edit User' : 'Pre-register New User'}</h2><button onClick={resetForm} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button></div>
              <form onSubmit={handleSubmit} className="p-6 space-y-4">
                <div><label className="block text-sm font-medium text-gray-700 mb-1">User Name *</label><input type="text" required value={formData.full_name} onChange={(e) => setFormData({ ...formData, full_name: e.target.value })} placeholder="User Name" className="w-full px-3 py-2 border rounded-lg" /></div>
                <div><label className="block text-sm font-medium text-gray-700 mb-1">Email ID *</label><input type="email" required value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} placeholder="Email ID" className="w-full px-3 py-2 border rounded-lg" /></div>
                <div><label className="block text-sm font-medium text-gray-700 mb-1">10-digit Mobile Number *</label><input type="tel" required value={formData.mobile} onChange={(e) => setFormData({ ...formData, mobile: e.target.value.replace(/[^0-9]/g, '') })} placeholder="Mobile Number" className="w-full px-3 py-2 border rounded-lg" maxLength="10" /></div>
                <div><label className="block text-sm font-medium text-gray-700 mb-1">Designation *</label><div className="flex items-center"><Combobox options={designations} value={formData.designation_id} onChange={(val) => setFormData(f => ({ ...f, designation_id: val }))} placeholder="Select Designation..." /><Button type="button" size="icon" variant="ghost" className="ml-2" onClick={() => setShowDesignationModal(true)}><Plus className="w-4 h-4" /></Button></div></div>
                <div><label className="block text-sm font-medium text-gray-700 mb-1">Role *</label><select required value={formData.role} onChange={(e) => setFormData({ ...formData, role: e.target.value })} className="w-full px-3 py-2 border rounded-lg"><option value="Employee">Employee</option><option value="Admin">Admin</option>{currentUser.role === 'Developer' && <option value="Developer">Developer</option>}</select></div>
                <div className="flex items-center justify-between">
                  <label className="block text-sm font-medium text-gray-700">Active Status</label>
                  <button type="button" onClick={() => setFormData({...formData, is_active: !formData.is_active})} className={`p-1 rounded-full ${formData.is_active ? 'text-green-600' : 'text-red-600'}`}>
                    {formData.is_active ? <ToggleRight className="w-8 h-8" /> : <ToggleLeft className="w-8 h-8" />}
                  </button>
                </div>
                <div className="flex justify-end space-x-4 pt-4"><Button type="button" variant="outline" onClick={resetForm}>Cancel</Button><Button type="submit" className="bg-gradient-to-r from-blue-500 to-purple-600">{selectedUser ? 'Update' : 'Pre-register'} User</Button></div>
              </form>
            </motion.div>
          </motion.div>
        )}
      </div>
    </>
  );
};

export default UserMaster;
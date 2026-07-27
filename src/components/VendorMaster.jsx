import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Plus, Search, Edit, Trash2, FileDown, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import { exportToPDF, exportToExcel } from '@/lib/exportUtils';
import { supabase } from '@/lib/customSupabaseClient';

const VendorMaster = () => {
  const [vendors, setVendors] = useState([]);
  const [filteredVendors, setFilteredVendors] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [selectedVendor, setSelectedVendor] = useState(null);
  const [formData, setFormData] = useState({ id: '', name: '', mobile: '', email: '', address: '' });
  const { toast } = useToast();

  const loadVendors = useCallback(async () => {
    const { data, error } = await supabase.from('tms_vendors').select('*').order('name', { ascending: true });
    if (error) {
      toast({ title: "Error loading vendors", description: error.message, variant: "destructive" });
    } else {
      setVendors(data);
    }
  }, [toast]);

  useEffect(() => {
    loadVendors();
  }, [loadVendors]);

  useEffect(() => {
    let filtered = vendors;
    if (searchTerm) {
      filtered = filtered.filter(vendor =>
        vendor.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (vendor.email && vendor.email.toLowerCase().includes(searchTerm.toLowerCase())) ||
        (vendor.mobile && vendor.mobile.includes(searchTerm))
      );
    }
    setFilteredVendors(filtered);
  }, [vendors, searchTerm]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (formData.mobile && !/^\d{10}$/.test(formData.mobile)) { toast({ title: "Invalid Mobile Number", description: "Please enter a 10-digit mobile number.", variant: "destructive" }); return; }
    
    const isDuplicate = vendors.some(
      v => v.name.toLowerCase() === formData.name.trim().toLowerCase() && v.id !== selectedVendor?.id
    );

    if (isDuplicate) {
      toast({ title: "Duplicate Vendor", description: "A vendor with this name already exists.", variant: "destructive" });
      return;
    }

    const upsertData = {
        name: formData.name.trim(),
        mobile: formData.mobile,
        email: formData.email,
        address: formData.address,
    };
    if(selectedVendor?.id) {
        upsertData.id = selectedVendor.id;
    }

    const { error } = await supabase.from('tms_vendors').upsert(upsertData);

    if (error) {
      toast({ title: "Error saving vendor", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: `Vendor ${selectedVendor ? 'updated' : 'added'} successfully!` });
      resetForm();
      loadVendors();
    }
  };

  const handleEdit = (vendor) => {
    setSelectedVendor(vendor);
    setFormData({
        id: vendor.id,
        name: vendor.name || '',
        mobile: vendor.mobile || '',
        email: vendor.email || '',
        address: vendor.address || ''
    });
    setShowForm(true);
  };

  const handleDelete = async (vendorId) => {
    const { error } = await supabase.from('tms_vendors').delete().eq('id', vendorId);
    if (error) {
      toast({ title: "Error deleting vendor", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: "Vendor deleted successfully!" });
      loadVendors();
    }
  };

  const resetForm = () => {
    setFormData({ id: '', name: '', mobile: '', email: '', address: '' });
    setSelectedVendor(null);
    setShowForm(false);
  };

  const handleDownload = (format) => {
    const headers = ["S.No.", "Name", "Mobile", "Email", "Address"];
    const data = filteredVendors.map((v, i) => [i + 1, v.name, v.mobile, v.email, v.address]);
    if (format === 'Excel') {
      exportToExcel(data, headers, 'Vendors_Report');
    } else {
      exportToPDF(data, headers, 'Vendors Report');
    }
  };

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="flex flex-col md:flex-row justify-between md:items-center gap-4">
        <div className="w-full md:w-auto">
          <h1 className="text-3xl font-bold gradient-text">Vendors</h1>
          <p className="text-gray-600">Manage your vendor partners</p>
        </div>
        <div className="flex gap-2 w-full md:w-auto">
          <Button onClick={() => { resetForm(); setShowForm(true); }} className="bg-gradient-to-r from-blue-500 to-purple-600 flex-1 md:flex-none"><Plus className="w-4 h-4 mr-2" /> Add Vendor</Button>
          <Button onClick={() => handleDownload('Excel')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> Excel</Button>
          <Button onClick={() => handleDownload('PDF')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> PDF</Button>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <div className="mb-6"><div className="relative"><Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" /><input type="text" placeholder="Search vendors..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" /></div></div>
        <div className="hidden md:block overflow-x-auto">
          <table className="w-full">
            <thead><tr className="border-b"><th className="p-3 text-left">S.No.</th><th className="p-3 text-left">Name</th><th className="p-3 text-left">Mobile</th><th className="p-3 text-left">Email</th><th className="p-3 text-left">Address</th><th className="p-3 text-left">Actions</th></tr></thead>
            <tbody>
              {filteredVendors.map((vendor, index) => (
                <motion.tr key={vendor.id} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="border-b hover:bg-gray-50/50">
                  <td className="p-3">{index + 1}</td><td className="p-3 font-medium">{vendor.name}</td><td className="p-3">{vendor.mobile}</td><td className="p-3">{vendor.email}</td><td className="p-3 max-w-xs truncate">{vendor.address}</td>
                  <td className="p-3"><div className="flex space-x-2"><button onClick={() => handleEdit(vendor)} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button><button onClick={() => handleDelete(vendor.id)} className="p-1 text-red-600 hover:bg-red-100 rounded"><Trash2 className="w-4 h-4" /></button></div></td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="md:hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
          {filteredVendors.map((vendor) => (
            <motion.div key={vendor.id} className="p-4 rounded-lg shadow-md space-y-2 border">
              <p className="font-bold">{vendor.name}</p><p className="text-sm text-gray-700">{vendor.email}</p><p className="text-sm text-gray-700">{vendor.mobile}</p><p className="text-sm text-gray-600 truncate">{vendor.address}</p>
              <div className="flex space-x-2 pt-2"><button onClick={() => handleEdit(vendor)} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button><button onClick={() => handleDelete(vendor.id)} className="p-1 text-red-600 hover:bg-red-100 rounded"><Trash2 className="w-4 h-4" /></button></div>
            </motion.div>
          ))}
        </div>
        {filteredVendors.length === 0 && <div className="text-center py-8"><p className="text-gray-500">No vendors found</p></div>}
      </motion.div>

      {showForm && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-md w-full">
            <div className="flex justify-between items-center p-6 border-b"><h2 className="text-xl font-bold gradient-text">{selectedVendor ? 'Edit Vendor' : 'Add New Vendor'}</h2><button onClick={resetForm} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button></div>
            <form onSubmit={handleSubmit} className="p-6 space-y-4">
              <input type="text" required value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="Vendor Name *" className="w-full px-3 py-2 border rounded-lg" />
              <input type="tel" value={formData.mobile} onChange={(e) => setFormData({ ...formData, mobile: e.target.value })} placeholder="10-digit Mobile Number" className="w-full px-3 py-2 border rounded-lg" maxLength="10" />
              <input type="email" value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} placeholder="Email ID" className="w-full px-3 py-2 border rounded-lg" />
              <textarea value={formData.address} onChange={(e) => setFormData({ ...formData, address: e.target.value })} rows={3} placeholder="Address" className="w-full px-3 py-2 border rounded-lg" />
              <div className="flex justify-end space-x-4 pt-4"><Button type="button" variant="outline" onClick={resetForm}>Cancel</Button><Button type="submit" className="bg-gradient-to-r from-blue-500 to-purple-600">{selectedVendor ? 'Update' : 'Add'} Vendor</Button></div>
            </form>
          </motion.div>
        </motion.div>
      )}
    </div>
  );
};

export default VendorMaster;
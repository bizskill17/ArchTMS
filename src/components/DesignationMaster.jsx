import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Plus, Search, Edit, Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import { supabase } from '@/lib/customSupabaseClient';

const DesignationMaster = () => {
  const [designations, setDesignations] = useState([]);
  const [filteredDesignations, setFilteredDesignations] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [selectedDesignation, setSelectedDesignation] = useState(null);
  const [formData, setFormData] = useState({ id: '', name: '' });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { toast } = useToast();

  const loadDesignations = useCallback(async () => {
    const { data, error } = await supabase.from('tms_designations').select('*').order('name', { ascending: true });
    if (error) {
      toast({ title: "Error loading designations", description: error.message, variant: "destructive" });
    } else {
      setDesignations(data);
    }
  }, [toast]);

  useEffect(() => {
    loadDesignations();
  }, [loadDesignations]);

  useEffect(() => {
    let filtered = designations;
    if (searchTerm) {
      filtered = filtered.filter(d =>
        d.name.toLowerCase().includes(searchTerm.toLowerCase())
      );
    }
    setFilteredDesignations(filtered);
  }, [designations, searchTerm]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      toast({ title: "Designation name is required", variant: "destructive" });
      return;
    }
    setIsSubmitting(true);

    const isDuplicate = designations.some(
      d => d.name.toLowerCase() === formData.name.trim().toLowerCase() && d.id !== selectedDesignation?.id
    );

    if (isDuplicate) {
      toast({ title: "Duplicate Designation", description: "This designation name already exists.", variant: "destructive" });
      setIsSubmitting(false);
      return;
    }

    const { error } = await supabase.from('tms_designations').upsert({ id: selectedDesignation?.id, name: formData.name.trim() });

    if (error) {
      toast({ title: "Error saving designation", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: `Designation ${selectedDesignation ? 'updated' : 'added'}.` });
      resetForm();
      loadDesignations();
    }
    setIsSubmitting(false);
  };

  const handleEdit = (designation) => {
    setSelectedDesignation(designation);
    setFormData(designation);
    setShowForm(true);
  };

  const handleDelete = async (id) => {
    const { error } = await supabase.from('tms_designations').delete().eq('id', id);
    if (error) {
      toast({ title: "Error deleting designation", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: "Designation removed." });
      loadDesignations();
    }
  };

  const resetForm = () => {
    setFormData({ id: '', name: '' });
    setSelectedDesignation(null);
    setShowForm(false);
  };

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="flex flex-col md:flex-row justify-between md:items-center gap-4">
        <div className="w-full md:w-auto">
          <h1 className="text-3xl font-bold gradient-text">Designations</h1>
          <p className="text-gray-600">Manage user designations</p>
        </div>
        <Button onClick={() => { resetForm(); setShowForm(true); }} className="bg-gradient-to-r from-blue-500 to-purple-600 w-full md:w-auto">
          <Plus className="w-4 h-4 mr-2" /> Add Designation
        </Button>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <div className="mb-6">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input type="text" placeholder="Search designations..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" />
          </div>
        </div>

        <div className="hidden md:block overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b">
                <th className="p-3 text-left">S.No.</th>
                <th className="p-3 text-left">Designation Name</th>
                <th className="p-3 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredDesignations.map((d, index) => (
                <motion.tr key={d.id} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="border-b hover:bg-gray-50/50">
                  <td className="p-3">{index + 1}</td>
                  <td className="p-3 font-medium">{d.name}</td>
                  <td className="p-3">
                    <div className="flex space-x-2">
                      <button onClick={() => handleEdit(d)} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button>
                      <button onClick={() => handleDelete(d.id)} className="p-1 text-red-600 hover:bg-red-100 rounded"><Trash2 className="w-4 h-4" /></button>
                    </div>
                  </td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        </div>
        
        <div className="md:hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
          {filteredDesignations.map((d) => (
            <motion.div key={d.id} className="p-4 rounded-lg shadow-md flex justify-between items-center border">
              <p className="font-bold">{d.name}</p>
              <div className="flex space-x-2">
                <button onClick={() => handleEdit(d)} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button>
                <button onClick={() => handleDelete(d.id)} className="p-1 text-red-600 hover:bg-red-100 rounded"><Trash2 className="w-4 h-4" /></button>
              </div>
            </motion.div>
          ))}
        </div>

        {filteredDesignations.length === 0 && <div className="text-center py-8"><p className="text-gray-500">No designations found</p></div>}
      </motion.div>

      {showForm && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <motion.div initial={{ scale: 0.9 }} animate={{ scale: 1 }} className="bg-white rounded-xl shadow-2xl max-w-md w-full">
            <div className="flex justify-between items-center p-6 border-b">
              <h2 className="text-xl font-bold gradient-text">{selectedDesignation ? 'Edit Designation' : 'Add New Designation'}</h2>
              <button onClick={resetForm} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleSubmit} className="p-6 space-y-4">
              <input type="text" required value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="Designation Name" className="w-full p-2 border rounded-lg" />
              <div className="flex justify-end space-x-4 pt-4">
                <Button type="button" variant="outline" onClick={resetForm}>Cancel</Button>
                <Button type="submit" disabled={isSubmitting} className="bg-gradient-to-r from-blue-500 to-purple-600">{isSubmitting ? "Saving..." : (selectedDesignation ? 'Update' : 'Add')}</Button>
              </div>
            </form>
          </motion.div>
        </motion.div>
      )}
    </div>
  );
};

export default DesignationMaster;
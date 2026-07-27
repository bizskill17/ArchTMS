import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Plus, Search, Edit, Trash2, FileDown, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/use-toast';
import { exportToPDF, exportToExcel } from '@/lib/exportUtils';
import { supabase } from '@/lib/customSupabaseClient';

const CategoryMaster = () => {
  const [categories, setCategories] = useState([]);
  const [filteredCategories, setFilteredCategories] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [selectedCategory, setSelectedCategory] = useState(null);
  const [formData, setFormData] = useState({ id: '', name: '' });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { toast } = useToast();

  const loadCategories = useCallback(async () => {
    const { data, error } = await supabase.from('tms_categories').select('*').order('name', { ascending: true });
    if (error) {
      toast({ title: "Error loading categories", description: error.message, variant: "destructive" });
    } else {
      setCategories(data);
    }
  }, [toast]);

  useEffect(() => {
    loadCategories();
  }, [loadCategories]);

  useEffect(() => {
    let filtered = categories;
    if (searchTerm) {
      filtered = filtered.filter(cat =>
        cat.name.toLowerCase().includes(searchTerm.toLowerCase())
      );
    }
    setFilteredCategories(filtered);
  }, [categories, searchTerm]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      toast({ title: "Category name is required", variant: "destructive" });
      return;
    }
    setIsSubmitting(true);

    const isDuplicate = categories.some(
      c => c.name.toLowerCase() === formData.name.trim().toLowerCase() && c.id !== selectedCategory?.id
    );

    if (isDuplicate) {
      toast({ title: "Duplicate Category", description: "This category name already exists.", variant: "destructive" });
      setIsSubmitting(false);
      return;
    }

    const { error } = await supabase.from('tms_categories').upsert({ id: selectedCategory?.id, name: formData.name.trim() });

    if (error) {
      toast({ title: "Error saving category", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: `Category ${selectedCategory ? 'updated' : 'added'} successfully!` });
      resetForm();
      loadCategories();
    }
    setIsSubmitting(false);
  };

  const handleEdit = (category) => {
    setSelectedCategory(category);
    setFormData({ id: category.id, name: category.name });
    setShowForm(true);
  };

  const handleDelete = async (categoryId) => {
    const { error } = await supabase.from('tms_categories').delete().eq('id', categoryId);
    if (error) {
      toast({ title: "Error deleting category", description: error.message, variant: "destructive" });
    } else {
      toast({ title: "Success", description: "Category deleted successfully!" });
      loadCategories();
    }
  };

  const resetForm = () => {
    setFormData({ id: '', name: '' });
    setSelectedCategory(null);
    setShowForm(false);
  };

  const handleDownload = (format) => {
    const headers = ["S.No.", "Category Name"];
    const data = filteredCategories.map((cat, index) => [
      index + 1,
      cat.name
    ]);
    if (format === 'Excel') {
      exportToExcel(data, headers, 'Categories_Report');
    } else {
      exportToPDF(data, headers, 'Categories Report');
    }
  };

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="flex flex-col md:flex-row justify-between md:items-center gap-4">
        <div className="w-full md:w-auto">
          <h1 className="text-3xl font-bold gradient-text">Categories</h1>
          <p className="text-gray-600">Manage task categories</p>
        </div>
        <div className="flex gap-2 w-full md:w-auto">
          <Button onClick={() => { resetForm(); setShowForm(true); }} className="bg-gradient-to-r from-blue-500 to-purple-600 flex-1 md:flex-none"><Plus className="w-4 h-4 mr-2" /> Add Category</Button>
          <Button onClick={() => handleDownload('Excel')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> Excel</Button>
          <Button onClick={() => handleDownload('PDF')} variant="outline" className="flex-1 md:flex-none"><FileDown className="w-4 h-4 mr-2" /> PDF</Button>
        </div>
      </motion.div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="task-card">
        <div className="mb-6">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input type="text" placeholder="Search categories..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" />
          </div>
        </div>

        <div className="hidden md:block overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b">
                <th className="p-3 text-left">S.No.</th><th className="p-3 text-left">Category Name</th><th className="p-3 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredCategories.map((category, index) => (
                <motion.tr key={category.id} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="border-b hover:bg-gray-50/50">
                  <td className="p-3">{index + 1}</td>
                  <td className="p-3 font-medium">{category.name}</td>
                  <td className="p-3">
                    <div className="flex space-x-2">
                      <button onClick={() => handleEdit(category)} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button>
                      <button onClick={() => handleDelete(category.id)} className="p-1 text-red-600 hover:bg-red-100 rounded"><Trash2 className="w-4 h-4" /></button>
                    </div>
                  </td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="md:hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
          {filteredCategories.map((category, index) => (
            <motion.div key={category.id} className="p-4 rounded-lg shadow-md flex justify-between items-center border">
              <div>
                <p className="font-bold">{category.name}</p>
                <p className="text-sm text-gray-500">S.No: {index + 1}</p>
              </div>
              <div className="flex space-x-2">
                <button onClick={() => handleEdit(category)} className="p-1 text-green-600 hover:bg-green-100 rounded"><Edit className="w-4 h-4" /></button>
                <button onClick={() => handleDelete(category.id)} className="p-1 text-red-600 hover:bg-red-100 rounded"><Trash2 className="w-4 h-4" /></button>
              </div>
            </motion.div>
          ))}
        </div>

        {filteredCategories.length === 0 && <div className="text-center py-8"><p className="text-gray-500">No categories found</p></div>}
      </motion.div>

      {showForm && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <motion.div initial={{ scale: 0.9, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} className="bg-white rounded-xl shadow-2xl max-w-md w-full">
            <div className="flex justify-between items-center p-6 border-b">
              <h2 className="text-xl font-bold gradient-text">{selectedCategory ? 'Edit Category' : 'Add New Category'}</h2>
              <button onClick={resetForm} className="p-2 hover:bg-gray-100 rounded-lg"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleSubmit} className="p-6 space-y-4">
              <input type="text" required value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="Category Name *" className="w-full px-3 py-2 border rounded-lg" />
              <div className="flex justify-end space-x-4 pt-4">
                <Button type="button" variant="outline" onClick={resetForm}>Cancel</Button>
                <Button type="submit" disabled={isSubmitting} className="bg-gradient-to-r from-blue-500 to-purple-600">{isSubmitting ? 'Saving...' : selectedCategory ? 'Update Category' : 'Add Category'}</Button>
              </div>
            </form>
          </motion.div>
        </motion.div>
      )}
    </div>
  );
};

export default CategoryMaster;
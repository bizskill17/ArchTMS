import React, { useState, useEffect, useCallback, useMemo } from "react";
import { motion } from "framer-motion";
import { Plus, Search, Edit, Trash2, FileDown, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useToast } from "@/components/ui/use-toast";
import { exportToPDF, exportToExcel } from "@/lib/exportUtils";
import { supabase } from "@/lib/customSupabaseClient";

/* ---------- Reusable UI ---------- */
const SectionTitle = ({ children }) => (
  <div className="text-sm font-semibold text-indigo-700 mt-6 mb-2 border-b pb-1">
    {children}
  </div>
);

const FieldView = ({ label, value }) => (
  <div className="flex flex-col">
    <span className="text-xs text-gray-500">{label}</span>
    <span className="text-sm font-medium text-gray-800">{value || "-"}</span>
  </div>
);

const Input = (props) => (
  <input
    {...props}
    className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 ${props.className || ""}`}
  />
);

const TextArea = (props) => (
  <textarea
    {...props}
    className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 ${props.className || ""}`}
  />
);

const Select = (props) => (
  <select
    {...props}
    className={`w-full px-3 py-2 border rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 ${props.className || ""}`}
  />
);

/* ---------- Main ---------- */
const SiteMaster = () => {
  const { toast } = useToast();
  const [sites, setSites] = useState([]);
  const [filteredSites, setFilteredSites] = useState([]);
  const [searchTerm, setSearchTerm] = useState("");

  const [showForm, setShowForm] = useState(false);
  const [showDetails, setShowDetails] = useState(false);

  const [selectedSite, setSelectedSite] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [projectTypes, setProjectTypes] = useState([]);

  // Only two filters as requested
  const [filters, setFilters] = useState({
    projectTypeId: "",
    category: "",
  });

  const defaultForm = {
    id: "",
    name: "",
    address: "",
    telegram_group_id: "",
    project_id: "",
    client_name: "",
    project_type_id: null,
    architect_interiors: "",
    architect_interiors_2: "",
    asst_architect_interiors: "",
    in_house_structural: "",
    structural_consultant: "",
    mep_consultant: "",
    landscape_consultant: "",
    views_3d: "",
    working: "",
    permission_drawing: "",
    site_engineer: "",
    project_category: "",
    project_status_1: "",
    project_status_2: "",
    project_status_3: "",
  };

  const [formData, setFormData] = useState(defaultForm);

  const headers = useMemo(
    () => [
      "S.No.",
      "Project ID",
      "Project Name",
      "Client Name",
      "Category",
      "Project Type",
      "Address",
    ],
    []
  );

  /* ---------- Loaders ---------- */
  const loadSites = useCallback(async () => {
const { data, error } = await supabase
  .from("tms_sites")
  .select("*")
  .order("project_id", { ascending: true, nullsFirst: false });

    if (error) {
      toast({
        title: "Error loading sites",
        description: error.message,
        variant: "destructive",
      });
      return;
    }
    setSites(data || []);
    setFilteredSites(data || []);
  }, [toast]);

  const loadProjectTypes = useCallback(async () => {
    const { data, error } = await supabase
      .from("tms_projecttypes")
      .select("id, project_type")
      .order("project_type");
    if (!error) setProjectTypes(data || []);
  }, []);

  useEffect(() => {
    loadSites();
    loadProjectTypes();
  }, [loadSites, loadProjectTypes]);

  /* ---------- Global search + two filters ---------- */
  useEffect(() => {
    const term = (searchTerm || "").toLowerCase().trim();

    const next = sites.filter((s) => {
      // global search
      const passesGlobal =
        !term ||
        (s.name || "").toLowerCase().includes(term) ||
        (s.address || "").toLowerCase().includes(term) ||
        (s.client_name || "").toLowerCase().includes(term) ||
        (s.project_id || "").toLowerCase().includes(term);

      if (!passesGlobal) return false;

      // filter: category
      const byCategory =
        !filters.category ||
        (s.project_category || "").toLowerCase() === filters.category.toLowerCase();

      // filter: project type
      const byType =
        !filters.projectTypeId ||
        String(s.project_type_id || "") === String(filters.projectTypeId);

      return byCategory && byType;
    });

    setFilteredSites(next);
  }, [searchTerm, sites, filters]);

  /* ---------- UI helpers ---------- */
  const openAdd = () => {
    setSelectedSite(null);
    setFormData(defaultForm);
    setShowForm(true);
  };

  const openEdit = (site) => {
    setSelectedSite(site);
    setFormData({ ...defaultForm, ...site });
    setShowForm(true);
  };

  const closeForm = () => {
    setShowForm(false);
    setIsSubmitting(false);
  };

  const openDetails = (site) => {
    setSelectedSite(site);
    setShowDetails(true);
  };

  const closeDetails = () => setShowDetails(false);

  /* ---------- Save ---------- */
  const handleSave = async (e) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      toast({
        title: "Project Name required",
        description: "Enter a site name.",
        variant: "destructive",
      });
      return;
    }
    if (!formData.project_category) {
      toast({
        title: "Category required",
        description: "Select Architect or Interior.",
        variant: "destructive",
      });
      return;
    }

    setIsSubmitting(true);

    const payload = {
      name: formData.name.trim(),
      address: formData.address || null,
      telegram_group_id: formData.telegram_group_id || null,
//      project_id: formData.project_id || null,
      project_id: selectedSite?.id ? formData.project_id : null,
      client_name: formData.client_name || null,
      project_type_id: formData.project_type_id ?? null,
      architect_interiors: formData.architect_interiors || null,
      architect_interiors_2: formData.architect_interiors_2 || null,
      asst_architect_interiors: formData.asst_architect_interiors || null,
      in_house_structural: formData.in_house_structural || null,
      structural_consultant: formData.structural_consultant || null,
      mep_consultant: formData.mep_consultant || null,
      landscape_consultant: formData.landscape_consultant || null,
      views_3d: formData.views_3d || null,
      working: formData.working || null,
      permission_drawing: formData.permission_drawing || null,
      site_engineer: formData.site_engineer || null,
      project_category: formData.project_category,
      project_status_1: formData.project_status_1 || null,
      project_status_2: formData.project_status_2 || null,
      project_status_3: formData.project_status_3 || null,
    };
console.log("🚨 FINAL PAYLOAD SENT TO SUPABASE:", JSON.stringify(payload, null, 2));


    const { error } = selectedSite?.id
    
      ? await supabase.from("tms_sites").update(payload).eq("id", selectedSite.id)
      
      : await supabase.from("tms_sites").insert(payload);

    if (error) {
      toast({ title: "Error saving", description: error.message, variant: "destructive" });
    } else {
      toast({
        title: "Success",
        description: `Site ${selectedSite ? "updated" : "added"} successfully.`,
      });
      closeForm();
      loadSites();
    }
    setIsSubmitting(false);
  };

  /* ---------- Delete ---------- */
  const handleDelete = async (id) => {
    const { error } = await supabase.from("tms_sites").delete().eq("id", id);
    if (error) {
      toast({ title: "Delete failed", description: error.message, variant: "destructive" });
      return;
    }
    toast({ title: "Deleted", description: "Project removed." });
    loadSites();
  };

  /* ---------- Export ---------- */
  const handleExport = (type) => {
    const data = filteredSites.map((s, i) => [
      i + 1,
      s.project_id || "",
      s.name || "",
      s.client_name || "",
      s.project_category || "",
      projectTypes.find((p) => p.id === s.project_type_id)?.project_type || "",
      s.address || "",
    ]);
    type === "Excel"
      ? exportToExcel(data, headers, "Projects_Report")
      : exportToPDF(data, headers, "Projects_Report");
  };

  /* ---------- Render ---------- */
  return (
    <div className="p-4 md:p-6">
      {/* Header */}
      <div className="flex justify-between mb-4">
        <h1 className="text-2xl font-bold gradient-text">Projects</h1>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => handleExport("PDF")}>
            <FileDown className="w-4 h-4 mr-2" /> PDF
          </Button>
          <Button variant="outline" onClick={() => handleExport("Excel")}>
            <FileDown className="w-4 h-4 mr-2" /> Excel
          </Button>
          <Button onClick={openAdd}>
            <Plus className="w-4 h-4 mr-2" /> Add Project
          </Button>
        </div>
      </div>

      {/* Search + filters row */}
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:gap-3 mb-4">
        {/* Search expands */}
        <div className="relative md:flex-1">
          <Search className="absolute left-3 top-2.5 w-4 h-4 text-gray-400" />
          <Input
            placeholder="Search projects..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="pl-10"
          />
        </div>

        {/* Filters on the right */}
        <div className="flex gap-2 md:w-auto">
          <Select
            value={filters.projectTypeId}
            onChange={(e) => setFilters((f) => ({ ...f, projectTypeId: e.target.value }))}
            className="min-w-[180px]"
          >
            <option value="">Project Type</option>
            {projectTypes.map((p) => (
              <option key={p.id} value={p.id}>
                {p.project_type}
              </option>
            ))}
          </Select>

          <Select
            value={filters.category}
            onChange={(e) => setFilters((f) => ({ ...f, category: e.target.value }))}
            className="min-w-[180px]"
          >
            <option value="">Project Category</option>
            <option value="Architect">Architect</option>
            <option value="Interior">Interior</option>
          </Select>
        </div>
      </div>

      {/* Table */}
      <div className="overflow-x-auto rounded-lg border shadow-sm">
        <table className="min-w-[900px] w-full border border-gray-200">
          <thead className="bg-indigo-50 text-gray-700 text-sm">
            <tr>
              {headers.map((h) => (
                <th key={h} className="p-3 text-left font-semibold border border-gray-200">
                  {h}
                </th>
              ))}
              <th className="p-3 text-left font-semibold border border-gray-200">Actions</th>
            </tr>
          </thead>
          <tbody>
            {filteredSites.map((s, i) => (
              <motion.tr
                key={s.id}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                onClick={() => openDetails(s)}
                className="hover:bg-indigo-50/40 cursor-pointer"
              >
                <td className="p-3 border border-gray-200">{i + 1}</td>
                {/* keep ID in one line */}
                <td className="p-3 border border-gray-200 whitespace-nowrap">{s.project_id || "-"}</td>
                <td className="p-3 border border-gray-200 font-medium">{s.name || "-"}</td>
                <td className="p-3 border border-gray-200">{s.client_name || "-"}</td>
                <td className="p-3 border border-gray-200">{s.project_category || "-"}</td>
                <td className="p-3 border border-gray-200">
                  {projectTypes.find((p) => p.id === s.project_type_id)?.project_type || "-"}
                </td>
                <td className="p-3 border border-gray-200">{s.address || "-"}</td>
                <td className="p-3 border border-gray-200">
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={(e) => {
                        e.stopPropagation();
                        openEdit(s);
                      }}
                    >
                      <Edit className="w-4 h-4" />
                    </Button>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDelete(s.id);
                      }}
                    >
                      <Trash2 className="w-4 h-4" />
                    </Button>
                  </div>
                </td>
              </motion.tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* ---------- Details Modal ---------- */}
      {showDetails && selectedSite && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4"
        >
          <motion.div
            initial={{ scale: 0.95, y: 20 }}
            animate={{ scale: 1, y: 0 }}
            className="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto border"
          >
            <div className="flex justify-between items-center p-6 border-b bg-indigo-50">
              <h2 className="text-lg font-semibold text-indigo-800">
                {selectedSite.name}
              </h2>
              <button onClick={closeDetails} className="p-2 hover:bg-gray-200 rounded-lg">
                <X className="w-5 h-5 text-gray-600" />
              </button>
            </div>

            <div className="p-6 space-y-6 text-sm">
              <SectionTitle>Basic Info</SectionTitle>
              <div className="grid grid-cols-2 gap-4">
                <FieldView label="Project ID" value={selectedSite.project_id} />
                <FieldView label="Client Name" value={selectedSite.client_name} />
                <FieldView label="Category" value={selectedSite.project_category} />
                <FieldView
                  label="Project Type"
                  value={projectTypes.find((p) => p.id === selectedSite.project_type_id)?.project_type}
                />
                <FieldView label="Telegram Group ID" value={selectedSite.telegram_group_id} />
                <FieldView label="Address" value={selectedSite.address} />
              </div>

              <SectionTitle>Consultants</SectionTitle>
              <div className="grid grid-cols-2 gap-4">
                <FieldView label="Architect / Interior" value={selectedSite.architect_interiors} />
                <FieldView label="Architect / Interior - 2" value={selectedSite.architect_interiors_2} />
                <FieldView label="Asst. Architect / Interior" value={selectedSite.asst_architect_interiors} />
                <FieldView label="In-House Structural" value={selectedSite.in_house_structural} />
                <FieldView label="Structural Consultant" value={selectedSite.structural_consultant} />
                <FieldView label="MEP Consultant" value={selectedSite.mep_consultant} />
                <FieldView label="Landscape Consultant" value={selectedSite.landscape_consultant} />
              </div>

              <SectionTitle>Drawings</SectionTitle>
              <div className="grid grid-cols-2 gap-4">
                <FieldView label="3D Views" value={selectedSite.views_3d} />
                <FieldView label="Working" value={selectedSite.working} />
                <FieldView label="Permission Drawing" value={selectedSite.permission_drawing} />
              </div>

              <SectionTitle>Status</SectionTitle>
              <div className="grid grid-cols-3 gap-4">
                <FieldView label="Status 1" value={selectedSite.project_status_1} />
                <FieldView label="Status 2" value={selectedSite.project_status_2} />
                <FieldView label="Status 3" value={selectedSite.project_status_3} />
              </div>

              <SectionTitle>Team</SectionTitle>
              <FieldView label="Site Engineer" value={selectedSite.site_engineer} />
            </div>
          </motion.div>
        </motion.div>
      )}

      {/* ---------- Form Modal ---------- */}
      {showForm && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4"
        >
          <motion.div
            initial={{ scale: 0.95, y: 20 }}
            animate={{ scale: 1, y: 0 }}
            className="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto"
          >
            <div className="flex justify-between items-center p-6 border-b">
              <h2 className="text-xl font-bold gradient-text">
                {selectedSite?.id ? "Edit Project" : "Add New Project"}
              </h2>
              <button onClick={closeForm} className="p-2 hover:bg-gray-100 rounded-lg">
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSave} className="p-6 space-y-6">
              <SectionTitle>Basic Details</SectionTitle>
              <Input
                required
                placeholder="Project Name *"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
              <Input
                placeholder="Telegram Group ID"
                value={formData.telegram_group_id}
                onChange={(e) => setFormData({ ...formData, telegram_group_id: e.target.value })}
              />
              <TextArea
                rows={3}
                placeholder="Address"
                value={formData.address}
                onChange={(e) => setFormData({ ...formData, address: e.target.value })}
              />

              <SectionTitle>Project Info</SectionTitle>
            

              {selectedSite?.id ? (
                // EDIT MODE → Show actual ID (read-only)
                <Input
                  value={formData.project_id || ""}
                  readOnly
                  className="bg-gray-100 text-gray-500 cursor-not-allowed"
                />
              ) : (
                // ADD MODE → Show placeholder text (read-only)
                <Input
                  value="Will be auto-generated after save"
                  readOnly
                  className="bg-gray-100 text-gray-400 italic cursor-not-allowed"
                />
              )}

              <Input
                placeholder="Client Name"
                value={formData.client_name}
                onChange={(e) => setFormData({ ...formData, client_name: e.target.value })}
              />
              <Select
                value={formData.project_type_id ?? ""}
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    project_type_id: e.target.value ? Number(e.target.value) : null,
                  })
                }
              >
                <option value="">Select Project Type</option>
                {projectTypes.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.project_type}
                  </option>
                ))}
              </Select>
              <Select
                required
                value={formData.project_category}
                onChange={(e) => setFormData({ ...formData, project_category: e.target.value })}
              >
                <option value="">Select Category</option>
                <option value="Architect">Architect</option>
                <option value="Interior">Interior</option>
              </Select>

              <SectionTitle>Consultants</SectionTitle>
              <Input
                placeholder="Architect / Interior"
                value={formData.architect_interiors}
                onChange={(e) => setFormData({ ...formData, architect_interiors: e.target.value })}
              />
              <Input
                placeholder="Architect / Interior - 2"
                value={formData.architect_interiors_2}
                onChange={(e) => setFormData({ ...formData, architect_interiors_2: e.target.value })}
              />
              <Input
                placeholder="Asst. Architect / Interior"
                value={formData.asst_architect_interiors}
                onChange={(e) =>
                  setFormData({ ...formData, asst_architect_interiors: e.target.value })
                }
              />
              <Input
                placeholder="In-House Structural"
                value={formData.in_house_structural}
                onChange={(e) => setFormData({ ...formData, in_house_structural: e.target.value })}
              />
              <Input
                placeholder="Structural Consultant"
                value={formData.structural_consultant}
                onChange={(e) => setFormData({ ...formData, structural_consultant: e.target.value })}
              />
              <Input
                placeholder="MEP Consultant"
                value={formData.mep_consultant}
                onChange={(e) => setFormData({ ...formData, mep_consultant: e.target.value })}
              />
              <Input
                placeholder="Landscape Consultant"
                value={formData.landscape_consultant}
                onChange={(e) => setFormData({ ...formData, landscape_consultant: e.target.value })}
              />

              <SectionTitle>Drawings & Status</SectionTitle>
              <Input
                placeholder="3D Views"
                value={formData.views_3d}
                onChange={(e) => setFormData({ ...formData, views_3d: e.target.value })}
              />
              <Input
                placeholder="Working"
                value={formData.working}
                onChange={(e) => setFormData({ ...formData, working: e.target.value })}
              />
              <Input
                placeholder="Permission Drawing"
                value={formData.permission_drawing}
                onChange={(e) => setFormData({ ...formData, permission_drawing: e.target.value })}
              />
              <Input
                placeholder="Site Engineer"
                value={formData.site_engineer}
                onChange={(e) => setFormData({ ...formData, site_engineer: e.target.value })}
              />
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <Input
                  placeholder="Status 1"
                  value={formData.project_status_1}
                  onChange={(e) => setFormData({ ...formData, project_status_1: e.target.value })}
                />
                <Input
                  placeholder="Status 2"
                  value={formData.project_status_2}
                  onChange={(e) => setFormData({ ...formData, project_status_2: e.target.value })}
                />
                <Input
                  placeholder="Status 3"
                  value={formData.project_status_3}
                  onChange={(e) => setFormData({ ...formData, project_status_3: e.target.value })}
                />
              </div>

              <div className="flex justify-end gap-3">
                <Button type="button" variant="outline" onClick={closeForm}>
                  Cancel
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Saving…" : selectedSite?.id ? "Update" : "Add Project"}
                </Button>
              </div>
            </form>
          </motion.div>
        </motion.div>
      )}
    </div>
  );
};

export default SiteMaster;
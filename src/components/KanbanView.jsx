import React, { useState, useEffect, useCallback } from 'react';
import { DragDropContext, Droppable, Draggable } from 'react-beautiful-dnd';
import { motion } from 'framer-motion';
import { useToast } from '@/components/ui/use-toast';
import { supabase } from '@/lib/customSupabaseClient';
import UpdateTaskModal from '@/components/UpdateTaskModal';
import TaskDetails from '@/components/TaskDetails';
import { Button } from '@/components/ui/button';
import { PenLine as FilePenLine, Info, Search, Loader2 } from 'lucide-react';
import { Combobox } from '@/components/ui/combobox';

const KanbanView = ({ currentUser }) => {
  const [columns, setColumns] = useState(null);
  const [allTasks, setAllTasks] = useState([]);
  const [isUpdating, setIsUpdating] = useState(false);
  const [isViewing, setIsViewing] = useState(false);
  const [selectedTask, setSelectedTask] = useState(null);
  const [filters, setFilters] = useState({ searchTerm: '', owner: 'all', assignee: 'all', site: 'all' });
  const [users, setUsers] = useState([]);
  const [sites, setSites] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const { toast } = useToast();

  const loadData = useCallback(async () => {
    setIsLoading(true);
    let query = supabase.from('tms_tasks').select(`
      *,
      category:tms_categories(name),
      site:tms_sites(name),
      task_assignees:tms_task_assignees(user:tms_users(id, full_name)),
      task_owners:tms_task_owners(user:tms_users(id, full_name))
    `);

    const { data, error } = await query;

    if (error) {
      toast({ title: "Error loading tasks", description: error.message, variant: "destructive" });
    } else {
      const formattedTasks = data.map(t => ({
        ...t,
        category: t.category?.name,
        siteName: t.site?.name,
        assignees: t.task_assignees.map(a => a.user.full_name),
        assigneeIds: t.task_assignees.map(a => a.user.id),
        taskOwners: t.task_owners.map(o => o.user.full_name),
        taskOwnerIds: t.task_owners.map(o => o.user.id),
      }));
      setAllTasks(formattedTasks);
    }
    setIsLoading(false);
  }, [toast]);
  
  useEffect(() => {
    loadData();
    const loadDropdownData = async () => {
      const { data: usersData } = await supabase.from('tms_users').select('id, full_name').order('full_name');
      setUsers(usersData?.map(u => ({ value: u.id, label: u.full_name })) || []);
      const { data: sitesData } = await supabase.from('tms_sites').select('id, name').order('name');
      setSites(sitesData?.map(s => ({ value: s.id, label: s.name })) || []);
    };
    loadDropdownData();
  }, [loadData]);
  
  useEffect(() => {
    let filtered = [...allTasks];
    if (filters.searchTerm) filtered = filtered.filter(t => t.task.toLowerCase().includes(filters.searchTerm.toLowerCase()));
    if (filters.owner !== 'all') filtered = filtered.filter(t => (t.taskOwnerIds ?? []).map(String).includes(String(filters.owner)));
    if (filters.assignee !== 'all') filtered = filtered.filter(t => (t.assigneeIds ?? []).map(String).includes(String(filters.assignee)));
    if (filters.site !== 'all') filtered = filtered.filter(t => String(t.site_id) === String(filters.site));

    const newColumns = {
      'Not Yet Started': { name: 'Not Yet Started', items: [] },
      'In Progress': { name: 'In Progress', items: [] },
      'Completed': { name: 'Completed', items: [] },
    };
    filtered.forEach(task => {
      if (newColumns[task.status]) {
        newColumns[task.status].items.push(task);
      }
    });
    setColumns(newColumns);
  }, [allTasks, filters]);

  const onDragEnd = async (result, cols, setCols) => {
    if (!result.destination || !cols) return;
    const { source, destination, draggableId } = result;

    if (source.droppableId !== destination.droppableId) {
      const sourceColumn = cols[source.droppableId];
      const destColumn = cols[destination.droppableId];
      const sourceItems = [...sourceColumn.items];
      const destItems = [...destColumn.items];
      const [removed] = sourceItems.splice(source.index, 1);
      
      const newStatus = destination.droppableId;
      removed.status = newStatus;

      destItems.splice(destination.index, 0, removed);

      setCols({
        ...cols,
        [source.droppableId]: { ...sourceColumn, items: sourceItems },
        [destination.droppableId]: { ...destColumn, items: destItems },
      });

      const { error } = await supabase
        .from('tms_tasks')
        .update({ status: newStatus })
        .eq('id', draggableId);

      if (error) {
        toast({ title: "Error updating task status", description: error.message, variant: "destructive" });
        loadData();
      } else {
        toast({ title: "Task Updated", description: `Task moved to ${newStatus}` });
        await supabase.from('tms_action_log').insert({
          task_id: draggableId,
          user_id: currentUser.id,
          status: newStatus,
          remark: `Status changed via Kanban drag-and-drop.`
        });
      }
    } else {
      const column = cols[source.droppableId];
      const copiedItems = [...column.items];
      const [removed] = copiedItems.splice(source.index, 1);
      copiedItems.splice(destination.index, 0, removed);
      setCols({
        ...cols,
        [source.droppableId]: { ...column, items: copiedItems },
      });
    }
  };

  const handleSaveTask = () => {
    setIsUpdating(false);
    setSelectedTask(null);
    loadData();
  };

  const getStatusColor = (status) => {
    switch (status) {
      case 'Not Yet Started': return 'border-yellow-400';
      case 'In Progress': return 'border-blue-400';
      case 'Completed': return 'border-green-400';
      default: return 'border-gray-400';
    }
  };

  const handleFilterChange = (filterName, value) => {
    setFilters(prev => ({ ...prev, [filterName]: value }));
  };

  const canEdit = (task) => currentUser.role === 'Admin' || currentUser.role === 'Developer' || (task.taskOwnerIds && task.taskOwnerIds.includes(currentUser.id));

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }}>
        <h1 className="text-3xl font-bold gradient-text">Kanban Board</h1>
        <p className="text-gray-600">Visualize your workflow</p>
      </motion.div>
      <div className="task-card p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-4">
          <div className="relative col-span-1 sm:col-span-2 md:col-span-1">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <input type="text" placeholder="Search tasks..." value={filters.searchTerm} onChange={(e) => handleFilterChange('searchTerm', e.target.value)} className="w-full pl-10 pr-4 py-2 border rounded-lg" />
          </div>
          <Combobox options={[{value: 'all', label: 'All Owners'}, ...users]} value={filters.owner} onChange={(val) => handleFilterChange('owner', val)} placeholder="Select Owner..."/>
          <Combobox options={[{value: 'all', label: 'All Assignees'}, ...users]} value={filters.assignee} onChange={(val) => handleFilterChange('assignee', val)} placeholder="Select Assignee..."/>
          <Combobox options={[{value: 'all', label: 'All Sites'}, ...sites]} value={filters.site} onChange={(val) => handleFilterChange('site', val)} placeholder="Select Site..."/>
        </div>
      </div>
      <div className="flex flex-col md:flex-row gap-4 h-[calc(100vh-18rem)]">
        {isLoading || !columns ? (
          <div className="flex items-center justify-center w-full h-full">
            <Loader2 className="w-8 h-8 animate-spin text-gray-500" />
          </div>
        ) : (
          <DragDropContext onDragEnd={result => onDragEnd(result, columns, setColumns)}>
            {Object.entries(columns).map(([columnId, column]) => (
              <div className="flex flex-col w-full md:w-1/3" key={columnId}>
                <div className={`p-4 rounded-t-lg shadow-md flex justify-between items-center bg-gray-100 border-b-4 ${getStatusColor(column.name)}`}>
                  <h2 className="font-semibold">{column.name}</h2>
                  <span className="text-sm font-bold bg-gray-200 rounded-full px-2 py-1">{column.items.length}</span>
                </div>
                <div className="bg-gray-50 rounded-b-lg flex-grow overflow-y-auto p-2">
                  <Droppable droppableId={columnId}>
                    {(provided, snapshot) => (
                      <div
                        {...provided.droppableProps}
                        ref={provided.innerRef}
                        className={`min-h-[100px] transition-colors duration-200 ${snapshot.isDraggingOver ? 'bg-blue-100' : ''}`}
                      >
                        {column.items.map((item, index) => (
                          <Draggable key={item.id} draggableId={item.id} index={index}>
                            {(provided, snapshot) => (
                              <div
                                ref={provided.innerRef}
                                {...provided.draggableProps}
                                {...provided.dragHandleProps}
                                className={`p-3 mb-2 rounded-lg shadow-sm bg-white border-l-4 ${snapshot.isDragging ? 'shadow-lg' : ''} ${getStatusColor(column.name)}`}
                              >
                                <p className="font-semibold text-sm">{item.task}</p>
                                <p className="text-xs text-gray-500 mt-1">Due: {item.due_date}</p>
                                <p className="text-xs text-gray-500">Assignees: {item.assignees.join(', ')}</p>
                                <div className="flex justify-end gap-2 mt-2">
                                  <Button size="sm" variant="ghost" onClick={() => { setSelectedTask(item); setIsViewing(true); }}><Info className="w-4 h-4"/></Button>
                                  <Button size="sm" variant="outline" onClick={() => { setSelectedTask(item); setIsUpdating(true); }}>Update</Button>
                                  {canEdit(item) && <Button size="sm" variant="outline" onClick={() => { setSelectedTask(item); setIsUpdating(true); }}><FilePenLine className="w-4 h-4"/></Button>}
                                </div>
                              </div>
                            )}
                          </Draggable>
                        ))}
                        {provided.placeholder}
                      </div>
                    )}
                  </Droppable>
                </div>
              </div>
            ))}
          </DragDropContext>
        )}
      </div>
      {isUpdating && selectedTask && <UpdateTaskModal task={selectedTask} onSave={handleSaveTask} onClose={() => { setIsUpdating(false); setSelectedTask(null); }} currentUser={currentUser} />}
      {isViewing && selectedTask && <TaskDetails task={selectedTask} onClose={() => { setIsViewing(false); setSelectedTask(null); }} />}
    </div>
  );
};

export default KanbanView;
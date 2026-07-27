import jsPDF from 'jspdf';
import 'jspdf-autotable';

export const exportToPDF = (data, headers, title) => {
  const doc = new jsPDF();
  doc.text(title, 14, 15);
  doc.autoTable({
    head: [headers],
    body: data,
    startY: 20,
    theme: 'grid',
    styles: { fontSize: 8 },
    headStyles: { fillColor: [22, 160, 133] },
  });
  doc.save(`${title.replace(/ /g, '_')}.pdf`);
};

export const exportGroupedToPDF = (dataToExport, headers, title, groupBy, mapFunction) => {
  const groupedData = dataToExport.reduce((acc, task) => {
      let key;
      if (groupBy === 'site') key = task.siteName || 'Unassigned';
      else if (groupBy === 'assignee') key = task.assignees.join(', ') || 'Unassigned';
      else if (groupBy === 'owner') key = task.taskOwners.join(', ') || 'Unassigned';
      else key = 'Unknown Group';
      
      if (!acc[key]) acc[key] = [];
      acc[key].push(task);
      return acc;
  }, {});
  
  const doc = new jsPDF();
  doc.text(`${title} (Grouped by ${groupBy.charAt(0).toUpperCase() + groupBy.slice(1)})`, 14, 15);
  let y = 20;

  Object.entries(groupedData).forEach(([groupName, groupTasks]) => {
      if (y > 250) {
        doc.addPage();
        y = 20;
      }
      doc.text(groupName, 14, y + 5);
      const body = groupTasks.map(mapFunction);
      doc.autoTable({ head: [headers], body, startY: y + 8, theme: 'grid', styles: { fontSize: 8 }, headStyles: { fillColor: [22, 160, 133] } });
      y = doc.autoTable.previous.finalY + 10;
  });

  doc.save(`${title.replace(/ /g, '_')}_by_${groupBy}.pdf`);
};


export const exportToExcel = (data, headers, fileName) => {
  const csvContent = "data:text/csv;charset=utf-8," 
    + [headers.join(","), ...data.map(row => row.map(field => `"${String(field).replace(/"/g, '""')}"`).join(","))].join("\n");
  
  const encodedUri = encodeURI(csvContent);
  const link = document.createElement("a");
  link.setAttribute("href", encodedUri);
  link.setAttribute("download", `${fileName}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
};
function openModal(id){const el=document.getElementById(id);if(el)el.classList.add('show')}
function closeModal(id){const el=document.getElementById(id);if(el)el.classList.remove('show')}
function moveItem(id,name){document.getElementById('moveId').value=id;document.getElementById('moveName').textContent='Оборудование: '+name;openModal('moveModal')}
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('show')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal.show').forEach(m=>m.classList.remove('show'))});

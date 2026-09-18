(() => {
    'use strict';
    const root=document.querySelector('.mseo');if(!root)return;
    const agenda=document.getElementById('mseo-agenda-filter');
    let agendaPage=0;
    function filterAgenda(){
        const company=document.getElementById('mseo-agenda-client').value;
        const rows=[...root.querySelectorAll('[data-mseo-agenda]')];
        const matches=rows.filter(row=>(agenda.value==='all'||row.dataset[agenda.value]==='1')&&(!company||row.dataset.client===company));
        agendaPage=Math.min(agendaPage,Math.max(0,Math.ceil(matches.length/12)-1));
        const visible=new Set(matches.slice(agendaPage*12,(agendaPage+1)*12));rows.forEach(row=>row.hidden=!visible.has(row));
        document.getElementById('mseo-agenda-empty').hidden=matches.length>0;
        document.getElementById('mseo-agenda-count').textContent=matches.length?`${agendaPage*12+1}–${Math.min((agendaPage+1)*12,matches.length)} of ${matches.length} actionable tasks`: '0 actionable tasks';
        document.getElementById('mseo-agenda-prev').disabled=agendaPage===0;
        document.getElementById('mseo-agenda-next').disabled=(agendaPage+1)*12>=matches.length;
    }
    if(agenda){
        [agenda,document.getElementById('mseo-agenda-client')].forEach(node=>node.addEventListener('change',()=>{agendaPage=0;filterAgenda();}));
        document.getElementById('mseo-agenda-prev').onclick=()=>{agendaPage--;filterAgenda();};
        document.getElementById('mseo-agenda-next').onclick=()=>{agendaPage++;filterAgenda();};filterAgenda();
    }
    document.getElementById('mseo-client-search')?.addEventListener('input',event=>root.querySelectorAll('[data-mseo-client]').forEach(card=>{card.hidden=!card.dataset.mseoClient.includes(event.target.value.toLowerCase());}));
    document.getElementById('mseo-task-filter')?.addEventListener('change',event=>{const value=event.target.value;root.querySelectorAll('[data-task-status]').forEach(task=>{task.hidden=value==='all'?false:value==='open'?['completed','not_applicable'].includes(task.dataset.taskStatus):task.dataset.taskStatus!==value;});root.querySelectorAll('.mseo-category').forEach(category=>{category.open=value!=='all';});});
    function reveal(){if(!location.hash)return;const target=document.getElementById(location.hash.slice(1));if(!target)return;let node=target;while(node&&node!==root){if(node.tagName==='DETAILS')node.open=true;node=node.parentElement;}target.scrollIntoView({block:'start'});}
    window.addEventListener('hashchange',reveal);reveal();
    root.querySelector('[data-mseo-print]')?.addEventListener('click',()=>window.print());
    let dirty=false;root.querySelectorAll('form[method="post"]').forEach(form=>{form.addEventListener('input',()=>dirty=true);form.addEventListener('submit',()=>dirty=false);});
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
})();

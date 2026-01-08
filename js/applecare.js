
var format_applecare_yes_no = function(colNumber, row){
    var col = $('td:eq('+colNumber+')', row),
        colvar = col.text();
    colvar = colvar == '0' ? i18n.t('No') :
    colvar = (colvar == '1' ? i18n.t('Yes') : colvar)
    console.log(colvar)
    col.html(colvar)
}



var format_applecare_DateToMoment = function(colNumber, row){
    var col = $('td:eq('+colNumber+')', row),
        date = col.text();
    if(moment(date, 'YYYY-MM-DD 00:00:00', true).isValid())
    {
        console.log("here")
        var formatted='<time title="'+ moment(date, 'YYYY-MM-DD h:mm:ss').fromNow() + '" </time>' + moment(date, 'YYYY-MM-DD h:mm:ss').format("MM-DD-YYYY");
        col.html(formatted);
    } else if (date) {
        col.text(date)
    }
}
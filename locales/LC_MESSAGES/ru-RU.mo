��    �      �  �   �      �     �     �     �     �  	   �  
   �     �  	   �  
   �  
   �     �     �     �  	         
               "     )     2  �   :     �     �  
   �     �     �     �     �                     (     0  !   H  /   j     �  F  �     �     �     �               $     *     1     :     A     G     _     d     j     p     x          �     �     �     �     �     �     �  	   �     �     �  /   �     *     F     N  	   W     a     i     o     �     �     �  �   �     9  	   L     V     [     l     o     t  	   }  o   �  M  �  A  E  �   �  �  8  �   $               #  
   ,  
   7     B     G     U     ]  	   k     u     {     �  
   �     �     �     �     �     �     �     �     �     �     �     �     �     �                         %     7     C     c     l     t     �     �     �      �     �  *   �                    "     '     -  z   2     �  	   �     �  	   �  
   �     �     �     �               ,     ;     L     \     y     �     �  #  �  .   �   *   �   6    !  	   W!  
   a!  
   l!     w!     �!     �!     �!     �!  	   �!     �!     �!     �!     �!     �!  9   �!  G   "  ,   e"     �"     �"  )   �"  ~   �"     J#  �  N#      %     ,%  	   <%     F%     X%     h%     v%     �%     �%     �%     �%     �%  	   �%     �%     �%     &     %&     -&     ?&     N&  �   [&     '     ''     <'     X'     m'     t'     �'     �'     �'  #   �'     �'  .   (  d   <(  G   �(     �(  %  �(     +     -+  3   <+     p+     �+     �+     �+     �+      �+     �+  0   ,     =,  	   F,     P,     _,     t,     �,     �,     �,     �,     �,     �,  ,   �,     '-     @-     S-  8   d-  Z   �-  .   �-     '.     <.     N.     i.     v.  +   �.  !   �.     �.     �.  I  �.     70     O0     ^0  #   c0     �0     �0     �0     �0  �   �0  �  �1  m  �4  �  7  �  �8  �  `<     L>     Y>     u>     �>     �>     �>     �>     �>  %   ?  #   '?     K?     Z?  %   j?     �?  -   �?     �?      �?     @     @  
   +@     6@     Q@     W@     ^@     f@     u@     |@  '   �@     �@     �@     �@  #   �@     A  `   !A     �A     �A     �A     �A     �A  %   �A  Z    B  8   [B  J   �B     �B     �B     
C  
   C     $C     3C    FC  
   ^D     iD     �D     �D     �D     �D     �D     �D  N   E      gE     �E     �E  6   �E  *   �E     F  1   'F  !   YF  �  {F  �   ZH  L   �H  Q   ;I  
   �I     �I  #   �I  "   �I     �I     �I     J  &   J     9J     HJ     ]J     nJ     �J  &   �J  S   �J  j   K  x   xK     �K     L  \   %L  �   �L     dM     }   )   Z   �      �       !      '   <   �           �      �   (   �              P   �   �             &   ]   �      x   z         0   J   +   T   �   b   �   �             $       X   ?       �   �   j   �       i   o      �   �   t   �   N   O   �       �          h       #   	   M   H              �   a   9   %   d   �   �      U       �   A   V   �   �          *   .              G           C   �   |               n       �       l   �       >      �   v   �   F   �      B   �          �   s   �   �   �          K   �   �                  2           r   
   �                     �       ^   �          ;   �          �   �   3   {                   7   �   @       �          �   I       �   `   E                          [   "       :   4   u       �   �   c   q   k   Q   W   y   \       Y   8   m   ~       ,   �       �   _           �   =   w       /   �   e   g   6       �   D       -   L      �   S   5       1   R           f           p        %d Day %d Days %d Hour %d Hours %d Minute %d Minutes %d Month %d Months %d Per Day %d Records %d Week %d Weeks %d Year (Actions) (Edit) , Count: , Host: , URL: 1 Minute 1 Month A comma delimited list of domains that you wish to remove from the syslog hostname, Examples would be 'mydomain.com, otherdomain.com' Actions Alert Alert Name Alerts All All Facilities All Programs All Records All Text Background Upgrade By User Cacti Syslog Alert '%s' Cacti Syslog Threshold Alert '%s' Cacti Syslog Threshold Alert '%s' and Host '%s' Cancel Choose the custom html wrapper and CSS file to use.  This file contains both html and CSS to wrap around your report.  If it contains more than simply CSS, you need to place a special <REPORT> tag inside of the file.  This format tag will be replaced by the report content.  These files are located in the 'formats' directory. Clear Command Command for Opening Tickets Contains Continue Count Count: Critical Custom Daily Data Retention Settings Date Date: Debug Default Delete Details Device Device Name Devices Disable Disabled Email Addresses Email Options Emergency Enable Enable HTML Based Email Enable Remote Data Collector Message Processing Enable Statistics Gathering Enabled Enabled? Ends with Entries Error Event Alert - %s Event Report - %s Export Facility For Threshold based Alerts, what is the maximum number that you wish to show in the report.  This is used to limit the size of the html log and Email. Format File to Use Frequency From General Settings Go Host Hostname Hostname: If this checkbox is set, all Emails will be sent in HTML format.  Otherwise, Emails will be sent in plain text. If this checkbox is set, all hostnames are validated.  If the hostname is not valid. All records are assigned to a special host called 'invalidhost'.  This setting can impact syslog processing time on large systems.  Therefore, use of this setting should only be used when other means are not in place to prevent this from happening. If this checkbox is set, records will be transferred from the Syslog Incoming table to the main syslog table and Alerts and Reports will be enabled.  Please keep in mind that if the system is disabled log entries will still accumulate into the Syslog Incoming table as this is defined by the rsyslog or syslog-ng process. If this checkbox is set, statistics on where syslog messages are arriving from will be maintained.  This statistical information can be used to render things such as heat maps. If your Remote Data Collectors have their own Syslog databases and process their messages independently, check this checkbox.  By checking this Checkbox, your Remote Data Collectors will need to maintain their own 'config_local.php' file in order to inform Syslog to use an independent database for message display and processing.  Please use the template file 'config_local.php.dist' for this purpose.  WARNING: Syslog tables will be automatically created as soon as this option is enabled. If your Remote Data Collectors have their own Syslog databases and process thrie messages independently, check this checkbox if you wish the Main Cacti databases Alerts, Removal and Report rules to be sent to the Remote Cacti System. Import Import/Export Imported Indefinite Individual Info Informational Install Last Modified Last Sent Level Level: Match String Match Type Max Report Records Message Message String: Message: Messages Method Multiple N/A Name Name: Never No None Normal User Not Set Notes Notice Notification List Open Ticket Please use an HTML Email Client Priority Program Record Type Records Refresh Refresh Interval Remote Data Collector Rules Sync Remote Message Processing Remove Everything (Logs, Tables, Settings) Report Name Reports Return Rows Rules Save Save Failed.  Remote Data Collectors in Sync Mode are not allowed to Save Rules.  Save from the Main Cacti Server instead. Search Send Time Severity Severity: Statistics Strip Domains Syslog Syslog %s Settings Syslog Alert Retention Syslog Data Only Syslog Enabled Syslog Retention Syslog Settings Syslog Uninstall Preferences System System Administration System Logs This command will be executed for opening Help Desk Tickets.  The command will be required to parse multiple input parameters as follows: <b>--alert-name</b>, <b>--severity</b>, <b>--hostlist</b>, <b>--message</b>.  The hostlist will be a comma delimited list of hosts impacted by the alert. This is the number of days to keep alert logs. This is the number of days to keep events. This is the time in seconds before the page refreshes. Threshold Threshold: Time Range Timespan To Transfer Trim Truncate Syslog Table Uninstall Unknown Update Updated Upgrade Validate Hostnames WARNING: A Syslog Instance Count Alert has Been Triggered WARNING: A Syslog Instance Count Alert has Been Triggered for Host '%s' WARNING: Syslog Upgrade is Time Consuming!!! Warning Weekly What uninstall method do you want to use? When uninstalling syslog, you can remove everything, or only components, just in case you plan on re-installing in the future. Yes Project-Id-Version: Russian (Cacti)
Report-Msgid-Bugs-To: 
PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE
Last-Translator: FULL NAME <EMAIL@ADDRESS>
Language-Team: Russian <http://translate.cacti.net/projects/cacti/syslog/ru/>
Language: ru
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit
Plural-Forms: nplurals=3; plural=n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2;
X-Generator: Weblate 4.8.1
 %d День %d Дня(ей) %d Час %d Часа(ов) %d Минута %d Минут %d Месяц %d Месяца %d в день %d Записи %d Неделя %d Недели %d Год (действия) (Редактировать) , Количество: , Host: , URL-АДРЕС: 1 минута 1 месяц Список доменов через запятую, которые вы хотите удалить из имени syslog хоста. Например 'mydomain.com, otherdomain.com' Действия Оповещение Имя оповещения Оповещения Все Все удобства Все Программы Все записи Весь текст Фоновое обновление Пользователем Предупреждение Cacti Syslog '%s' Предупреждение о пороговом значении плагина Cacti Syslog '%s' Cacti Syslog срабатывание алерта '%s' хоста '%s' Отмена Выберите пользовательскую оболочку html и файл CSS для использования. Этот файл содержит как html, так и CSS для обертки вашего отчета. Если он содержит больше, чем просто CSS, вам нужно поместить специальный тег <REPORT> внутри файла. Этот тег будет заменен содержимым отчета. Эти файлы находятся в каталоге 'formats'. Очистить Команда Команда для открытия тикета Содержит Продолжить Количество Количество: Критический Пользовательский Ежедневно Настройки хранения данных Дата Дата: Отладка Стандартно Удалить Детали Устройство Имя устройства Устройства Выключить Выключен Адрес электронной почты Параметры Email Аварийный Включить Электронная почта на основе HTML Включить обработку сообщений удаленного Data Collector Включить сбор статистики Включенные Включить? Заканчивается Записи Ошибка Оповещение о событии - %s Отчет о событии - %s Экспорт Объект Для оповещений на основе пороговых значений укажите максимальное число, которое вы хотите показать в отчете.  Используется для ограничения размера журнала html и электронной почты. Формат файла Частота От Основные Настройки Перейти Хост Имя хоста Имя хоста: Если этот флажок установлен, все письма будут отправляться в формате HTML. В противном случае письма будут отправляться открытым текстом. Если этот флажок установлен, все имена хостов будут проверяться. Если имя хоста недействительно, все записи закрепляются за специальным хостом под названием 'invalidhost'. Эта настройка может повлиять на время обработки системного журнала на больших системах. Поэтому использование этого параметра следует использовать только в том случае, если отсутствуют другие средства для предотвращения этого. Если этот флажок установлен, записи будут переноситься из таблицы входящих сообщений Syslog в основную таблицу системного журнала, а сигналы тревоги и отчеты будут включены. Пожалуйста, имейте в виду, что если система отключена, записи журнала будут накапливаться в таблице входящих сообщений Syslog, как это определено процессом rsyslog или syslog-ng. Если этот флажок установлен, будет вестись статистика о том, откуда приходят сообщения системного журнала. Эта статистическая информация может быть использована для отображения таких объектов, как тепловые карты. Если ваши удаленные сборщики данных имеют свои собственные базы данных Syslog и обрабатывают свои сообщения независимо, установите этот флажок. Установив этот флажок, ваши удаленные сборщики данных должны будут поддерживать свой собственный файл «config_local.php», чтобы сообщить Syslog о необходимости использования независимой базы данных для отображения и обработки сообщений. Для этой цели используйте файл шаблона config_local.php.dist. ПРЕДУПРЕЖДЕНИЕ. Таблицы системного журнала будут созданы автоматически, как только эта опция будет включена. Если ваши удаленные сборщики данных имеют свои собственные базы данных Syslog и обрабатывают свои сообщения независимо, установите этот флажок, если вы хотите, чтобы правила предупреждений, удаления и отчетов основных баз данных Cacti отправлялись в удаленную систему Cacti. Импорт Импорт/Экспорт Импортировано Всегда Индивидуальный Информация Информационный Установить Последнее изменение Последняя отправка Уровень Уровень: Строка соответствия Тип Матча Макс. количество отчётов Сообщение Строка сообщения: Сообщение: Сообщения Метод Множественный Н/Д Имя Имя: Никогда Нет Нет Обычный пользователь Не задано Заметки Уведомление Список уведомлений Открыть тикет Пожалуйста, используйте HTML-клиент электронной почты Приоритет Программа Тип записи Записи Обновить Интервал обновления Синхронизация правил удаленного сборщика данных Удалённая обработка сообщений Удалить все (журналы, таблицы, настройки) Название отчета Отчеты Вернуть Строк Правила Сохранить Сохранить не удалось. Удаленным сборщикам данных в режиме синхронизации не разрешено сохранять правила. Вместо этого сохраните с основного сервера Cacti. Поиск Время Отправки Важность Важность: Статистика Обрезать домены Системный журнал Syslog %s Настройки Сохранение оповещения в системном журнале Только данные syslog Syslog включён Хранение Syslog Настройки системного журнала Параметры удаления Syslog Система Администрирование системы Системные журналы Эта команда будет выполнена для открытия билетов в службу поддержки. Команда понимать нескольких входных параметров следующим образом: <b>--alert-name</b>, <b>- -severity</b>, <b>--hostlist</b>, <b>--message</b>. Список хостов разделён запятой, на которые распространяется предупреждения(alerts). Это количество дней, в течение которых должны храниться журналы предупреждений. Это количество дней для хранения событий. Это время в секундах до обновления страницы. Порог Порог: Временной диапазон Продолжительность Кому Перевод Обрезка Обрезать Syslog таблицу Удалить Неизвестно Обновить Обновлено Обновить Проверка имен хостов ВНИМАНИЕ: Количество сработавших алертов Syslog ВНИМАНИЕ: Количество сработавших алертов Syslog для хоста '%s' ВНИМАНИЕ: Обновление системного журнала отнимает много времени!!! Предупреждение Еженедельно Какой метод деинсталляции вы хотите использовать? Удаляя syslog, вы можете удалить все или только отдельные компоненты, на случай, если планируете переустановить его в будущем. Да 
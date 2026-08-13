FARMERS : 
    1. able to input their farms credentials
        [✔️] register the number of their farm
        [✔️] input the following:
            - location
            - surface area
            - breeding method
            - seeding date
            - water depts
        [✔️] report to the admin, filter by the following
            - damage report
            - feed back report (bugs and etc)
            - request report
            - and others
        [✔️] udate/edit their profile
            - change credebtials
            - uplode image
    
    2. recieves wether data
        [] current wether
        [✔️] realtime current tide type
        [✔️] water tenpreture
        [✔️] note or remainder
    
    3. make the system predicts the following:
        [✔️] quantity of harvest per KG
        [] grapt chat of harvest (history chart ~ make a new table for this)
        [] warning notification for wether



ADMIN :
    1. allow the admin to monitor the following:
        [✔️] alabled to espectate/monitor the dashboard of every farmers
        [✔️] monitor all the table in the database
        [✔️] read the reposrt of the farmers
            - filter the report base on the tags



add HARVEST BUTTON for HARVEST MODAL      [✔️] done?
add DELETE BUTTON in farm card (make a header part)      [✔️] done?
add delete feature:     [] done?
    soft delete (add new field named "isDeleted")
    archieving delete (make an exact copy of database or tabale - storess the deleted info there)
change the auto-increment of ID to hardocoded auto numbering the for the ID     [] done?


change the structure of the image path      [✔️] done?
    main project folder/
    |-> farm-dashboard/
    |-> admin-dashboard/
    |-> sign
    |-> DB-connection/
    |-> other-bootstrap-tempates .../
    |
    |-> ulpoad-image/
        |-> farmer/
        |       |-> farms/
        |       |       |-> 1/ (base on the user ID)
        |       |
        |       |-> profiles/
        |       |       |-> 1/ (base on the user ID)
        |       |
        |       |-> report/
        |               |-> 1/ (base on the user ID)
        |
        |-> admin/
                |-> profiles/

these are the our leader suggested to be added and fix:
- warning notification for weather (for typhon and drought, if possible use API that dont need a key) 
- fix the reporting photo (di nakikita yung mga sinend ni user na pic)
- fix the notification bar, make it functional(ayusin mo yong notif halimbawa nakapag set ka na ng seeding pagka enter kailangan lalabas din sa notification kung kailan siya ma h-harvest ) (kailanghan nalabas din sa notification kung may bagyo o typhoon )
- grapt chat of harvest (history chart ~ make a new table for this)
 	- change the admin graph to line graph



- monitor all the table in the database
- add a soft delete feature
- change the auto-increment of ID to hardocoded auto numbering the for the ID
- fix the farmer remainder (hindi nag a-update pag nag edit ng farm)
- fix add harvest record to farmer dashboard katulad ng nasa admin dashboard
- change and fix the user graph to line graph(kailangan nagkakaroon ng graph kapag 2 or more na ang harvest ng farmer)
- fix yong settings(kailangan may laman kapag pinindot)
- fix the delete logic(kapag nag delete nag e-error at hindi na d-delete)



~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ [ infomation Asurance ] ~~~~~~~~

fix the sign in page "Your account is locked due to too many failed attempts. Contact an administrator." alert
    - even when different account try to log in, it didn't let to log in

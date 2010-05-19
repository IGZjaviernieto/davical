<?php
/**
* Extend the vComponent to specifically handle VCARD resources
*/

require_once('AwlQuery.php');
require_once('vComponent.php');

class VCard extends vComponent {

  /**
CREATE TABLE addressbook_resource (
  dav_id INT8 NOT NULL REFERENCES caldav_data(dav_id) ON UPDATE CASCADE ON DELETE CASCADE PRIMARY KEY,
  version TEXT,
  uid TEXT,
  nickname TEXT,
  fn TEXT, -- fullname
  n TEXT, -- Name Surname;First names
  note TEXT,
  org TEXT,
  url TEXT,
  fburl TEXT,
  caluri TEXT
);
  */
  function Write( $dav_id, $exists = true ) {
    $qry = new AwlQuery();

    // Only run a local transaction if we're not in one already.
    $in_transaction = ($qry->TransactionState() == 1);
    if ( ! $in_transaction ) $qry->Begin();

    if ( $exists ) {
      $sql = 'UPDATE addressbook_resource SET version=:version, uid=:uid, nickname=:nickname, fn=:fn, n=:name,
note=:note, org=:org, url=:url, fburl=:fburl, caladruri=:caladruri, caluri=:caluri WHERE dav_id=:dav_id';
    }
    else {
      $sql = 'INSERT INTO addressbook_resource ( dav_id, version, uid, nickname, fn, n, note, org, url, fburl, caladruri, caluri )
VALUES( :dav_id, :version, :uid, :nickname, :fn, :name, :note, :org, :url, :fburl, :caladruri, :caluri )';
    }
    $params = array( ':dav_id' => $dav_id );

    /**
    * @TODO: Most of these allow duplicates, so we should save the preferred one in the user's language in such cases.
    */
    $params[':version'] = $this->GetPValue('VERSION');
    $params[':uid'] = $this->GetPValue('UID');
    $params[':nickname'] = $this->GetPValue('NICKNAME');
    $params[':fn'] = $this->GetPValue('FN');
    $params[':name'] = $this->GetPValue('N');
    $params[':note'] = $this->GetPValue('NOTE');
    $params[':org'] = $this->GetPValue('ORG');
    $params[':url'] = $this->GetPValue('URL');
    $params[':fburl'] = $this->GetPValue('FBURL');
    $params[':caladruri'] = $this->GetPValue('CALADRURI');
    $params[':caluri'] = $this->GetPValue('CALURI');

    $qry->QDo( $sql, $params );

    $this->WriteAddresses($dav_id);
    $this->WritePhones($dav_id);
    $this->WriteEmails($dav_id);

    if ( ! $in_transaction ) $qry->Commit();
  }


  /**
CREATE TABLE addressbook_address_adr (
  dav_id INT8 NOT NULL REFERENCES caldav_data(dav_id) ON UPDATE CASCADE ON DELETE CASCADE,
  type TEXT,
  box_no TEXT,
  unit_no TEXT,
  street_address TEXT,
  locality TEXT,
  region TEXT,
  postcode TEXT,
  country TEXT,
  property TEXT -- The full text of the property
);
  */
  function WriteAddresses( $dav_id ) {
    $addresses = $this->GetProperties('ADR');
    $qry = new AwlQuery();

    // Only run a local transaction if we're not in one already.
    $in_transaction = ($qry->TransactionState() == 1);
    if ( ! $in_transaction ) $qry->Begin();

    $params = array( ':dav_id' => $dav_id );
    $qry->QDo('DELETE FROM addressbook_address_adr WHERE dav_id = :dav_id', $params );
    foreach( $addresses AS $adr ) {
      $params[':type'] = $adr->GetParameterValue('TYPE');
      $address = explode(';',$adr->Value());
      $params[':box_no']   = $address[0];
      $params[':unit_no']  = $address[1];
      $params[':street_address'] = $address[2];
      $params[':locality'] = $address[3];
      $params[':region']   = $address[4];
      $params[':postcode'] = $address[5];
      $params[':country']  = $address[6];
      $params[':property'] = $adr->Render();
      $qry->QDo( 'INSERT INTO addressbook_address_adr (dav_id, type, box_no, unit_no, street_address, locality, region, postcode, country, property)
VALUES( :dav_id, :type, :box_no, :unit_no, :street_address, :locality, :region, :postcode, :country, :property)', $params );
    }
    if ( ! $in_transaction ) $qry->Commit();
  }


  /**
CREATE TABLE addressbook_address_tel (
  dav_id INT8 NOT NULL REFERENCES caldav_data(dav_id) ON UPDATE CASCADE ON DELETE CASCADE,
  type TEXT,
  tel TEXT,
  property TEXT -- The full text of the property
);
  */
  function WritePhones( $dav_id ) {
    $telephones = $this->GetProperties('TEL');
    $qry = new AwlQuery();

    // Only run a local transaction if we're not in one already.
    $in_transaction = ($qry->TransactionState() == 1);
    if ( ! $in_transaction ) $qry->Begin();

    $params = array( ':dav_id' => $dav_id );
    $qry->QDo('DELETE FROM addressbook_address_tel WHERE dav_id = :dav_id', $params );
    foreach( $telephones AS $tel ) {
      $params[':type'] = $tel->GetParameterValue('TYPE');
      if ( ! isset($params[':type']) ) $params[':type'] = 'voice';
      $params[':tel'] = $tel->Value();
      $params[':property'] = $tel->Render();
      $qry->QDo( 'INSERT INTO addressbook_address_tel (dav_id, type, tel, property) VALUES( :dav_id, :type, :tel, :property)', $params );
    }
    if ( ! $in_transaction ) $qry->Commit();
  }


  /**
CREATE TABLE addressbook_address_email (
  dav_id INT8 NOT NULL REFERENCES caldav_data(dav_id) ON UPDATE CASCADE ON DELETE CASCADE,
  type TEXT,
  email TEXT,
  property TEXT -- The full text of the property
);
  */
  function WriteEmails( $dav_id ) {
    $emails = $this->GetProperties('EMAIL');
    $qry = new AwlQuery();

    // Only run a local transaction if we're not in one already.
    $in_transaction = ($qry->TransactionState() == 1);
    if ( ! $in_transaction ) $qry->Begin();

    $params = array( ':dav_id' => $dav_id );
    $qry->QDo('DELETE FROM addressbook_address_email WHERE dav_id = :dav_id', $params );
    foreach( $emails AS $email ) {
      $params[':type'] = $email->GetParameterValue('TYPE');
      $params[':email'] = $email->Value();
      $params[':property'] = $email->Render();
      $qry->QDo( 'INSERT INTO addressbook_address_email (dav_id, type, email, property) VALUES( :dav_id, :type, :email, :property)', $params );
    }
    if ( ! $in_transaction ) $qry->Commit();
  }

}
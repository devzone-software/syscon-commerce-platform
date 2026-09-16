package pe.syscon.auth.domain;

import jakarta.persistence.*;

@Entity
public class Permission {
    @Id public String name;
    protected Permission() {}
    public Permission(String name) { this.name=name; }
}
